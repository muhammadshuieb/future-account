<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Bank;
use App\Models\Branch;
use App\Models\CashBox;
use App\Models\JournalDetail;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;

class ReportService
{
    public function __construct(protected CurrencyService $currencies) {}

    public function trialBalance(?string $asOf = null, ?int $branchId = null): array
    {
        $asOf = $asOf ?: now()->toDateString();

        $rows = Account::query()
            ->where('is_group', false)
            ->orderBy('code')
            ->get()
            ->map(function (Account $account) use ($asOf, $branchId) {
                $agg = JournalDetail::query()
                    ->where('account_id', $account->id)
                    ->whereHas('journalEntry', function ($q) use ($asOf, $branchId) {
                        $q->where('status', 'posted')->whereDate('entry_date', '<=', $asOf);
                        $this->scopeJournalBranch($q, $branchId);
                    })
                    ->selectRaw('COALESCE(SUM(debit),0) as debit, COALESCE(SUM(credit),0) as credit')
                    ->first();

                $debit = (float) ($agg->debit ?? 0);
                $credit = (float) ($agg->credit ?? 0);
                $balance = $account->nature === 'debit' ? $debit - $credit : $credit - $debit;

                return [
                    'account_id' => $account->id,
                    'code' => $account->code,
                    'name' => $account->name,
                    'type' => $account->type,
                    'debit' => $debit,
                    'credit' => $credit,
                    'balance' => round($balance, 2),
                    'debit_balance' => $balance >= 0 && $account->nature === 'debit' ? round($balance, 2) : ($balance < 0 && $account->nature === 'credit' ? round(abs($balance), 2) : 0),
                    'credit_balance' => $balance >= 0 && $account->nature === 'credit' ? round($balance, 2) : ($balance < 0 && $account->nature === 'debit' ? round(abs($balance), 2) : 0),
                ];
            })
            ->filter(fn ($r) => $r['debit'] != 0 || $r['credit'] != 0)
            ->values()
            ->all();

        return [
            'as_of' => $asOf,
            'branch_id' => $branchId,
            'rows' => $rows,
            'total_debit' => round(collect($rows)->sum('debit'), 2),
            'total_credit' => round(collect($rows)->sum('credit'), 2),
        ];
    }

    public function incomeStatement(?string $from = null, ?string $to = null, ?int $branchId = null): array
    {
        $from = $from ?: now()->startOfYear()->toDateString();
        $to = $to ?: now()->toDateString();

        $revenue = $this->sumByType('revenue', $from, $to, $branchId);
        $expense = $this->sumByType('expense', $from, $to, $branchId);

        return [
            'from' => $from,
            'to' => $to,
            'branch_id' => $branchId,
            'revenue' => $revenue,
            'expense' => $expense,
            'net_income' => round($revenue['total'] - $expense['total'], 2),
        ];
    }

    public function balanceSheet(?string $asOf = null, ?int $branchId = null): array
    {
        $asOf = $asOf ?: now()->toDateString();
        $tb = $this->trialBalance($asOf, $branchId);

        $group = fn (string $type) => collect($tb['rows'])->where('type', $type)->values()->all();

        $assets = $group('asset');
        $liabilities = $group('liability');
        $equity = $group('equity');

        $income = $this->incomeStatement(now()->startOfYear()->toDateString(), $asOf, $branchId);

        return [
            'as_of' => $asOf,
            'branch_id' => $branchId,
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'net_income' => $income['net_income'],
            'total_assets' => round(collect($assets)->sum(fn ($r) => $r['balance']), 2),
            'total_liabilities' => round(collect($liabilities)->sum(fn ($r) => $r['balance']), 2),
            'total_equity' => round(collect($equity)->sum(fn ($r) => $r['balance']) + $income['net_income'], 2),
        ];
    }

    public function cashFlow(?string $from = null, ?string $to = null, ?int $branchId = null): array
    {
        $from = $from ?: now()->startOfYear()->toDateString();
        $to = $to ?: now()->toDateString();

        $cashAccounts = $this->cashAccountIds();

        $movements = JournalDetail::query()
            ->whereIn('account_id', $cashAccounts)
            ->whereHas('journalEntry', function ($q) use ($from, $to, $branchId) {
                $q->where('status', 'posted')
                    ->whereDate('entry_date', '>=', $from)
                    ->whereDate('entry_date', '<=', $to);
                $this->scopeJournalBranch($q, $branchId);
            })
            ->with('journalEntry:id,entry_number,entry_date,description,branch_id')
            ->orderBy('id')
            ->get()
            ->map(fn (JournalDetail $d) => [
                'date' => $d->journalEntry?->entry_date?->toDateString(),
                'entry_number' => $d->journalEntry?->entry_number,
                'description' => $d->journalEntry?->description,
                'inflow' => (float) $d->debit,
                'outflow' => (float) $d->credit,
            ]);

        return [
            'from' => $from,
            'to' => $to,
            'branch_id' => $branchId,
            'rows' => $movements,
            'total_inflow' => round($movements->sum('inflow'), 2),
            'total_outflow' => round($movements->sum('outflow'), 2),
            'net' => round($movements->sum('inflow') - $movements->sum('outflow'), 2),
        ];
    }

    public function salesReport(?string $from = null, ?string $to = null, ?int $branchId = null): array
    {
        $from = $from ?: now()->startOfMonth()->toDateString();
        $to = $to ?: now()->toDateString();

        $invoices = SalesInvoice::query()
            ->where('status', 'posted')
            ->whereDate('invoice_date', '>=', $from)
            ->whereDate('invoice_date', '<=', $to)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->with('customer:id,name,code')
            ->orderBy('invoice_date')
            ->get();

        $totalDoc = round((float) $invoices->sum('total'), 2);
        $totalBase = round((float) $invoices->sum(fn ($i) => (float) ($i->base_amount ?: $i->total)), 2);

        return [
            'from' => $from,
            'to' => $to,
            'branch_id' => $branchId,
            'count' => $invoices->count(),
            'total' => $totalBase,
            'total_document' => $totalDoc,
            'total_base' => $totalBase,
            'rows' => $invoices,
        ];
    }

    public function purchaseReport(?string $from = null, ?string $to = null, ?int $branchId = null): array
    {
        $from = $from ?: now()->startOfMonth()->toDateString();
        $to = $to ?: now()->toDateString();

        $invoices = PurchaseInvoice::query()
            ->where('status', 'posted')
            ->whereDate('invoice_date', '>=', $from)
            ->whereDate('invoice_date', '<=', $to)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->with('supplier:id,name,code')
            ->orderBy('invoice_date')
            ->get();

        $totalDoc = round((float) $invoices->sum('total'), 2);
        $totalBase = round((float) $invoices->sum(fn ($i) => (float) ($i->base_amount ?: $i->total)), 2);

        return [
            'from' => $from,
            'to' => $to,
            'branch_id' => $branchId,
            'count' => $invoices->count(),
            'total' => $totalBase,
            'total_document' => $totalDoc,
            'total_base' => $totalBase,
            'rows' => $invoices,
        ];
    }

    public function inventoryReport(?int $branchId = null, ?int $warehouseId = null): array
    {
        $warehouseIds = null;
        if ($warehouseId !== null) {
            $warehouseIds = collect([$warehouseId]);
            if ($branchId !== null) {
                $belongs = Warehouse::query()
                    ->where('id', $warehouseId)
                    ->where('branch_id', $branchId)
                    ->exists();
                if (! $belongs) {
                    $warehouseIds = collect([]);
                }
            }
        } elseif ($branchId !== null) {
            $warehouseIds = Warehouse::query()->where('branch_id', $branchId)->pluck('id');
        }

        $products = Product::query()
            ->withSum(['stockLevels as on_hand' => function ($q) use ($warehouseIds) {
                if ($warehouseIds !== null) {
                    $q->whereIn('warehouse_id', $warehouseIds);
                }
            }], 'quantity')
            ->with(['category:id,name', 'unit:id,name,symbol'])
            ->orderBy('sku')
            ->get()
            ->map(fn (Product $p) => [
                'id' => $p->id,
                'sku' => $p->sku,
                'name' => $p->name,
                'on_hand' => (float) ($p->on_hand ?? 0),
                'cost_price' => (float) $p->cost_price,
                'value' => round((float) ($p->on_hand ?? 0) * (float) $p->cost_price, 2),
                'reorder_level' => (float) $p->reorder_level,
            ])
            ->when($branchId || $warehouseId, fn ($c) => $c->filter(fn ($r) => abs($r['on_hand']) > 0.0001)->values())
            ->values();

        $totalValue = round($products->sum('value'), 2);
        // Warehouse-level stock has no matching GL split, so the control account is only
        // comparable for the whole company or for a branch.
        $glValue = $warehouseId === null ? $this->inventoryControlBalance($branchId) : null;

        return [
            'branch_id' => $branchId,
            'warehouse_id' => $warehouseId,
            'rows' => $products,
            'total_value' => $totalValue,
            'gl_value' => $glValue,
            'variance' => $glValue === null ? null : round($totalValue - $glValue, 2),
        ];
    }

    /** Balance of the inventory control account (1104). */
    protected function inventoryControlBalance(?int $branchId = null): float
    {
        $accountId = Account::query()->where('code', '1104')->value('id');

        if (! $accountId) {
            return 0.0;
        }

        $agg = JournalDetail::query()
            ->where('account_id', $accountId)
            ->whereHas('journalEntry', function ($q) use ($branchId) {
                $q->where('status', 'posted');
                $this->scopeJournalBranch($q, $branchId);
            })
            ->selectRaw('COALESCE(SUM(debit),0) as debit, COALESCE(SUM(credit),0) as credit')
            ->first();

        return round((float) ($agg->debit ?? 0) - (float) ($agg->credit ?? 0), 2);
    }

    public function productMovement(
        int $productId,
        ?string $from = null,
        ?string $to = null,
        ?int $branchId = null,
        ?int $warehouseId = null,
    ): array {
        $from = $from ?: now()->startOfMonth()->toDateString();
        $to = $to ?: now()->toDateString();

        $rows = StockMovement::query()
            ->where('product_id', $productId)
            ->whereDate('movement_date', '>=', $from)
            ->whereDate('movement_date', '<=', $to)
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->when($branchId && ! $warehouseId, fn ($q) => $q->whereHas('warehouse', fn ($wq) => $wq->where('branch_id', $branchId)))
            ->with('warehouse:id,name,code,branch_id')
            ->orderBy('movement_date')
            ->get();

        return [
            'from' => $from,
            'to' => $to,
            'branch_id' => $branchId,
            'warehouse_id' => $warehouseId,
            'rows' => $rows,
        ];
    }

    public function taxReport(?string $from = null, ?string $to = null, ?int $branchId = null): array
    {
        $from = $from ?: now()->startOfMonth()->toDateString();
        $to = $to ?: now()->toDateString();

        // Tax is stored in the document currency, so it is converted before being summed.
        $salesTax = (float) SalesInvoice::query()
            ->where('status', 'posted')
            ->whereDate('invoice_date', '>=', $from)
            ->whereDate('invoice_date', '<=', $to)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->selectRaw('COALESCE(SUM(tax_amount * COALESCE(NULLIF(exchange_rate, 0), 1)), 0) AS base_tax')
            ->value('base_tax');

        $purchaseTax = (float) PurchaseInvoice::query()
            ->where('status', 'posted')
            ->whereDate('invoice_date', '>=', $from)
            ->whereDate('invoice_date', '<=', $to)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->selectRaw('COALESCE(SUM(tax_amount * COALESCE(NULLIF(exchange_rate, 0), 1)), 0) AS base_tax')
            ->value('base_tax');

        return [
            'from' => $from,
            'to' => $to,
            'branch_id' => $branchId,
            'currency' => $this->currencies->baseCurrency(),
            'output_vat' => round($salesTax, 2),
            'input_vat' => round($purchaseTax, 2),
            'net_vat' => round($salesTax - $purchaseTax, 2),
        ];
    }

    public function generalLedger(int $accountId, ?string $from = null, ?string $to = null, ?int $branchId = null): array
    {
        $from = $from ?: now()->startOfYear()->toDateString();
        $to = $to ?: now()->toDateString();
        $account = Account::query()->findOrFail($accountId);

        $openingAgg = JournalDetail::query()
            ->where('account_id', $accountId)
            ->whereHas('journalEntry', function ($q) use ($from, $branchId) {
                $q->where('status', 'posted')->whereDate('entry_date', '<', $from);
                $this->scopeJournalBranch($q, $branchId);
            })
            ->selectRaw('COALESCE(SUM(debit),0) as debit, COALESCE(SUM(credit),0) as credit')
            ->first();

        $openingDebit = (float) ($openingAgg->debit ?? 0);
        $openingCredit = (float) ($openingAgg->credit ?? 0);
        $openingBalance = $account->nature === 'debit'
            ? $openingDebit - $openingCredit
            : $openingCredit - $openingDebit;

        $details = JournalDetail::query()
            ->join('journal_entries', 'journal_details.journal_entry_id', '=', 'journal_entries.id')
            ->with(['journalEntry:id,entry_number,entry_date,description,status,branch_id'])
            ->where('journal_details.account_id', $accountId)
            ->where('journal_entries.status', 'posted')
            ->whereDate('journal_entries.entry_date', '>=', $from)
            ->whereDate('journal_entries.entry_date', '<=', $to)
            ->when($branchId, fn ($q) => $q->where('journal_entries.branch_id', $branchId))
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_entries.id')
            ->orderBy('journal_details.line_order')
            ->select('journal_details.*')
            ->get();

        $running = $openingBalance;
        $rows = [];

        foreach ($details as $detail) {
            $debit = (float) $detail->debit;
            $credit = (float) $detail->credit;
            $running += $account->nature === 'debit' ? $debit - $credit : $credit - $debit;

            $entry = $detail->journalEntry;
            $rows[] = [
                'date' => $entry->entry_date->toDateString(),
                'entry_number' => $entry->entry_number,
                'description' => $detail->memo ?: $entry->description,
                'debit' => $debit,
                'credit' => $credit,
                'balance' => round($running, 2),
            ];
        }

        return [
            'account' => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'nature' => $account->nature,
            ],
            'from' => $from,
            'to' => $to,
            'branch_id' => $branchId,
            'opening_balance' => round($openingBalance, 2),
            'closing_balance' => round($running, 2),
            'rows' => $rows,
        ];
    }

    public function profitReport(?string $from = null, ?string $to = null, ?int $branchId = null): array
    {
        $sales = $this->salesReport($from, $to, $branchId);
        $from = $sales['from'];
        $to = $sales['to'];

        $cogs = JournalDetail::query()
            ->whereHas('account', fn ($q) => $q->where('code', '5101'))
            ->whereHas('journalEntry', function ($q) use ($from, $to, $branchId) {
                $q->where('status', 'posted')
                    ->whereDate('entry_date', '>=', $from)
                    ->whereDate('entry_date', '<=', $to);
                $this->scopeJournalBranch($q, $branchId);
            })
            ->sum('debit');

        $gross = round($sales['total'] - (float) $cogs, 2);

        return [
            'from' => $from,
            'to' => $to,
            'branch_id' => $branchId,
            'sales' => $sales['total'],
            'cogs' => round((float) $cogs, 2),
            'gross_profit' => $gross,
        ];
    }

    /**
     * Complete branch report: key operational figures for one branch and period.
     */
    public function branchCompleteReport(int $branchId, ?string $from = null, ?string $to = null): array
    {
        $from = $from ?: now()->startOfMonth()->toDateString();
        $to = $to ?: now()->toDateString();
        $branch = Branch::query()->findOrFail($branchId);

        $sales = $this->salesReport($from, $to, $branchId);
        $purchases = $this->purchaseReport($from, $to, $branchId);
        $profit = $this->profitReport($from, $to, $branchId);
        $inventory = $this->inventoryReport($branchId);
        $tax = $this->taxReport($from, $to, $branchId);

        $receivables = (float) SalesInvoice::query()
            ->where('status', 'posted')
            ->where('branch_id', $branchId)
            ->whereDate('invoice_date', '<=', $to)
            ->selectRaw('COALESCE(SUM(COALESCE(base_amount, total) - COALESCE(paid_amount, 0) * COALESCE(exchange_rate, 1)), 0) as bal')
            ->value('bal');

        // Prefer document-currency unpaid converted roughly via remaining * exchange when base unpaid not tracked.
        $receivablesAlt = (float) SalesInvoice::query()
            ->where('status', 'posted')
            ->where('branch_id', $branchId)
            ->whereDate('invoice_date', '<=', $to)
            ->get()
            ->sum(fn ($i) => max(0, ((float) ($i->base_amount ?: $i->total)) - ((float) $i->paid_amount * (float) ($i->exchange_rate ?: 1))));

        $payables = (float) PurchaseInvoice::query()
            ->where('status', 'posted')
            ->where('branch_id', $branchId)
            ->whereDate('invoice_date', '<=', $to)
            ->get()
            ->sum(fn ($i) => max(0, ((float) ($i->base_amount ?: $i->total)) - ((float) $i->paid_amount * (float) ($i->exchange_rate ?: 1))));

        $cashBoxes = CashBox::query()
            ->where('branch_id', $branchId)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'currency']);

        $warehouseIds = Warehouse::query()->where('branch_id', $branchId)->pluck('id');
        $stockRows = StockLevel::query()
            ->whereIn('warehouse_id', $warehouseIds)
            ->with('product:id,cost_price')
            ->get();
        $stockValue = round($stockRows->sum(fn ($l) => (float) $l->quantity * (float) ($l->product?->cost_price ?? 0)), 2);

        return [
            'branch' => [
                'id' => $branch->id,
                'code' => $branch->code,
                'name' => $branch->name,
            ],
            'from' => $from,
            'to' => $to,
            'sales' => [
                'count' => $sales['count'],
                'total' => $sales['total'],
            ],
            'purchases' => [
                'count' => $purchases['count'],
                'total' => $purchases['total'],
            ],
            'profit' => [
                'sales' => $profit['sales'],
                'cogs' => $profit['cogs'],
                'gross_profit' => $profit['gross_profit'],
            ],
            'receivables' => round($receivablesAlt ?: $receivables, 2),
            'payables' => round($payables, 2),
            'cash_boxes' => $cashBoxes,
            'stock_value' => $stockValue ?: $inventory['total_value'],
            'inventory_total_value' => $inventory['total_value'],
            'tax' => [
                'output_vat' => $tax['output_vat'],
                'input_vat' => $tax['input_vat'],
                'net_vat' => $tax['net_vat'],
            ],
        ];
    }

    /**
     * Cash-and-bank accounts: the default ones plus any dedicated account attached to a
     * cash box or bank, which is how multi-currency boxes are set up.
     *
     * @return \Illuminate\Support\Collection<int, int>
     */
    protected function cashAccountIds(): \Illuminate\Support\Collection
    {
        return Account::query()->whereIn('code', ['1101', '1102'])->pluck('id')
            ->merge(CashBox::query()->whereNotNull('account_id')->pluck('account_id'))
            ->merge(Bank::query()->whereNotNull('account_id')->pluck('account_id'))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
    }

    protected function scopeJournalBranch(Builder $q, ?int $branchId): void
    {
        if ($branchId) {
            $q->where('branch_id', $branchId);
        }
    }

    protected function sumByType(string $type, string $from, string $to, ?int $branchId = null): array
    {
        $accounts = Account::query()->where('type', $type)->where('is_group', false)->orderBy('code')->get();

        $rows = $accounts->map(function (Account $account) use ($from, $to, $branchId) {
            $agg = JournalDetail::query()
                ->where('account_id', $account->id)
                ->whereHas('journalEntry', function ($q) use ($from, $to, $branchId) {
                    $q->where('status', 'posted')
                        ->whereDate('entry_date', '>=', $from)
                        ->whereDate('entry_date', '<=', $to);
                    $this->scopeJournalBranch($q, $branchId);
                })
                ->selectRaw('COALESCE(SUM(debit),0) as debit, COALESCE(SUM(credit),0) as credit')
                ->first();

            $debit = (float) ($agg->debit ?? 0);
            $credit = (float) ($agg->credit ?? 0);
            $amount = $account->nature === 'credit' ? $credit - $debit : $debit - $credit;

            return [
                'code' => $account->code,
                'name' => $account->name,
                'amount' => round($amount, 2),
            ];
        })->filter(fn ($r) => abs($r['amount']) > 0.001)->values()->all();

        return [
            'rows' => $rows,
            'total' => round(collect($rows)->sum('amount'), 2),
        ];
    }
}
