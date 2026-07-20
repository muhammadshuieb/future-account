<?php

namespace App\Services;

use App\Models\Account;
use App\Models\JournalDetail;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;

class ReportService
{
    public function trialBalance(?string $asOf = null): array
    {
        $asOf = $asOf ?: now()->toDateString();

        $rows = Account::query()
            ->where('is_group', false)
            ->orderBy('code')
            ->get()
            ->map(function (Account $account) use ($asOf) {
                $agg = JournalDetail::query()
                    ->where('account_id', $account->id)
                    ->whereHas('journalEntry', function ($q) use ($asOf) {
                        $q->where('status', 'posted')->whereDate('entry_date', '<=', $asOf);
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
            'rows' => $rows,
            'total_debit' => round(collect($rows)->sum('debit'), 2),
            'total_credit' => round(collect($rows)->sum('credit'), 2),
        ];
    }

    public function incomeStatement(?string $from = null, ?string $to = null): array
    {
        $from = $from ?: now()->startOfYear()->toDateString();
        $to = $to ?: now()->toDateString();

        $revenue = $this->sumByType('revenue', $from, $to);
        $expense = $this->sumByType('expense', $from, $to);

        return [
            'from' => $from,
            'to' => $to,
            'revenue' => $revenue,
            'expense' => $expense,
            'net_income' => round($revenue['total'] - $expense['total'], 2),
        ];
    }

    public function balanceSheet(?string $asOf = null): array
    {
        $asOf = $asOf ?: now()->toDateString();
        $tb = $this->trialBalance($asOf);

        $group = fn (string $type) => collect($tb['rows'])->where('type', $type)->values()->all();

        $assets = $group('asset');
        $liabilities = $group('liability');
        $equity = $group('equity');

        $income = $this->incomeStatement(now()->startOfYear()->toDateString(), $asOf);

        return [
            'as_of' => $asOf,
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'net_income' => $income['net_income'],
            'total_assets' => round(collect($assets)->sum(fn ($r) => $r['balance']), 2),
            'total_liabilities' => round(collect($liabilities)->sum(fn ($r) => $r['balance']), 2),
            'total_equity' => round(collect($equity)->sum(fn ($r) => $r['balance']) + $income['net_income'], 2),
        ];
    }

    public function cashFlow(?string $from = null, ?string $to = null): array
    {
        $from = $from ?: now()->startOfYear()->toDateString();
        $to = $to ?: now()->toDateString();

        $cashAccounts = Account::query()->whereIn('code', ['1101', '1102'])->pluck('id');

        $movements = JournalDetail::query()
            ->whereIn('account_id', $cashAccounts)
            ->whereHas('journalEntry', function ($q) use ($from, $to) {
                $q->where('status', 'posted')
                    ->whereDate('entry_date', '>=', $from)
                    ->whereDate('entry_date', '<=', $to);
            })
            ->with('journalEntry:id,entry_number,entry_date,description')
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

    public function inventoryReport(): array
    {
        $products = Product::query()
            ->withSum('stockLevels as on_hand', 'quantity')
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
            ]);

        return [
            'rows' => $products,
            'total_value' => round($products->sum('value'), 2),
        ];
    }

    public function productMovement(int $productId, ?string $from = null, ?string $to = null): array
    {
        $from = $from ?: now()->startOfMonth()->toDateString();
        $to = $to ?: now()->toDateString();

        $rows = StockMovement::query()
            ->where('product_id', $productId)
            ->whereDate('movement_date', '>=', $from)
            ->whereDate('movement_date', '<=', $to)
            ->with('warehouse:id,name,code')
            ->orderBy('movement_date')
            ->get();

        return ['from' => $from, 'to' => $to, 'rows' => $rows];
    }

    public function taxReport(?string $from = null, ?string $to = null): array
    {
        $from = $from ?: now()->startOfMonth()->toDateString();
        $to = $to ?: now()->toDateString();

        $salesTax = (float) SalesInvoice::query()
            ->where('status', 'posted')
            ->whereDate('invoice_date', '>=', $from)
            ->whereDate('invoice_date', '<=', $to)
            ->sum('tax_amount');

        $purchaseTax = (float) PurchaseInvoice::query()
            ->where('status', 'posted')
            ->whereDate('invoice_date', '>=', $from)
            ->whereDate('invoice_date', '<=', $to)
            ->sum('tax_amount');

        return [
            'from' => $from,
            'to' => $to,
            'output_vat' => round($salesTax, 2),
            'input_vat' => round($purchaseTax, 2),
            'net_vat' => round($salesTax - $purchaseTax, 2),
            'note' => 'تقرير ضريبة مبسّط — stub',
        ];
    }

    public function profitReport(?string $from = null, ?string $to = null): array
    {
        $sales = $this->salesReport($from, $to);
        $cogs = JournalDetail::query()
            ->whereHas('account', fn ($q) => $q->where('code', '5101'))
            ->whereHas('journalEntry', function ($q) use ($from, $to) {
                $from = $from ?: now()->startOfMonth()->toDateString();
                $to = $to ?: now()->toDateString();
                $q->where('status', 'posted')
                    ->whereDate('entry_date', '>=', $from)
                    ->whereDate('entry_date', '<=', $to);
            })
            ->sum('debit');

        $gross = round($sales['total'] - (float) $cogs, 2);

        return [
            'from' => $sales['from'],
            'to' => $sales['to'],
            'sales' => $sales['total'],
            'cogs' => round((float) $cogs, 2),
            'gross_profit' => $gross,
        ];
    }

    protected function sumByType(string $type, string $from, string $to): array
    {
        $accounts = Account::query()->where('type', $type)->where('is_group', false)->orderBy('code')->get();

        $rows = $accounts->map(function (Account $account) use ($from, $to) {
            $agg = JournalDetail::query()
                ->where('account_id', $account->id)
                ->whereHas('journalEntry', function ($q) use ($from, $to) {
                    $q->where('status', 'posted')
                        ->whereDate('entry_date', '>=', $from)
                        ->whereDate('entry_date', '<=', $to);
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
