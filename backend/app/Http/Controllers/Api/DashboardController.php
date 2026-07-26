<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AppNotification;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Models\Setting;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class DashboardController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $branchId = $request->filled('branch_id') ? (int) $request->query('branch_id') : null;
        $currencyFilter = $request->filled('currency')
            ? strtoupper(trim((string) $request->query('currency')))
            : null;
        if ($currencyFilter === '') {
            $currencyFilter = null;
        }

        $baseCurrency = strtoupper((string) Setting::getValue('currency', 'USD'));
        $displayCurrency = $currencyFilter ?: $baseCurrency;
        $hasDocFilters = $branchId !== null || $currencyFilter !== null;

        $days = (int) $request->query('days', 7);
        $days = in_array($days, [7, 30], true) ? $days : 7;
        $fromDate = now()->subDays($days - 1)->startOfDay();

        if ($hasDocFilters) {
            $revenue = $this->sumDocumentAmounts(
                $this->applyDocFilters(SalesInvoice::query()->where('status', 'posted'), $branchId, $currencyFilter),
                $currencyFilter
            );
            $expense = $this->sumDocumentAmounts(
                $this->applyDocFilters(PurchaseInvoice::query()->where('status', 'posted'), $branchId, $currencyFilter),
                $currencyFilter
            );
        } else {
            $totalsByType = Account::query()
                ->select('accounts.type')
                ->selectRaw('COALESCE(SUM(journal_details.debit), 0) as total_debit')
                ->selectRaw('COALESCE(SUM(journal_details.credit), 0) as total_credit')
                ->leftJoin('journal_details', 'journal_details.account_id', '=', 'accounts.id')
                ->leftJoin('journal_entries', function ($join) {
                    $join->on('journal_entries.id', '=', 'journal_details.journal_entry_id')
                        ->where('journal_entries.status', '=', 'posted');
                })
                ->groupBy('accounts.type')
                ->get()
                ->keyBy('type');

            $revenue = (float) ($totalsByType->get('revenue')?->total_credit ?? 0)
                - (float) ($totalsByType->get('revenue')?->total_debit ?? 0);
            $expense = (float) ($totalsByType->get('expense')?->total_debit ?? 0)
                - (float) ($totalsByType->get('expense')?->total_credit ?? 0);
        }

        $receivables = (float) $this->applyDocFilters(
            SalesInvoice::query()->where('status', 'posted'),
            $branchId,
            $currencyFilter
        )->get()->sum(fn (SalesInvoice $inv) => $this->unpaidAmount($inv, $currencyFilter));

        $payables = (float) $this->applyDocFilters(
            PurchaseInvoice::query()->where('status', 'posted'),
            $branchId,
            $currencyFilter
        )->get()->sum(fn (PurchaseInvoice $inv) => $this->unpaidAmount($inv, $currencyFilter));

        $lowStockAlerts = app(\App\Services\InventoryService::class)->lowStockAlerts();
        if ($branchId !== null) {
            $branchWarehouseIds = \App\Models\Warehouse::query()
                ->where('branch_id', $branchId)
                ->pluck('id')
                ->all();
            $lowStockAlerts = array_values(array_filter(
                $lowStockAlerts,
                fn (array $row) => $row['warehouse_id'] !== null && in_array((int) $row['warehouse_id'], $branchWarehouseIds, true),
            ));
        }
        $lowStock = count($lowStockAlerts);
        $lowStockWarehouseNames = array_values(array_unique(array_filter(array_map(
            fn (array $row) => $row['warehouse_name'] ?? null,
            $lowStockAlerts,
        ))));

        $monthSales = $this->sumDocumentAmounts(
            $this->applyDocFilters(
                SalesInvoice::query()
                    ->where('status', 'posted')
                    ->whereMonth('invoice_date', now()->month)
                    ->whereYear('invoice_date', now()->year),
                $branchId,
                $currencyFilter
            ),
            $currencyFilter
        );

        $monthPurchases = $this->sumDocumentAmounts(
            $this->applyDocFilters(
                PurchaseInvoice::query()
                    ->where('status', 'posted')
                    ->whereMonth('invoice_date', now()->month)
                    ->whereYear('invoice_date', now()->year),
                $branchId,
                $currencyFilter
            ),
            $currencyFilter
        );

        $dailySales = $this->applyDocFilters(
            SalesInvoice::query()
                ->where('status', 'posted')
                ->where('invoice_date', '>=', $fromDate->toDateString()),
            $branchId,
            $currencyFilter
        )->get()
            ->groupBy(fn (SalesInvoice $i) => $i->invoice_date->toDateString())
            ->map(fn ($group, $date) => [
                'date' => $date,
                'total' => round($group->sum(fn (SalesInvoice $i) => $this->documentAmount($i, $currencyFilter)), 2),
                'count' => $group->count(),
            ])
            ->values()
            ->sortBy('date')
            ->values()
            ->all();

        $dailyPurchases = $this->applyDocFilters(
            PurchaseInvoice::query()
                ->where('status', 'posted')
                ->where('invoice_date', '>=', $fromDate->toDateString()),
            $branchId,
            $currencyFilter
        )->get()
            ->groupBy(fn (PurchaseInvoice $i) => $i->invoice_date->toDateString())
            ->map(fn ($group, $date) => [
                'date' => $date,
                'total' => round($group->sum(fn (PurchaseInvoice $i) => $this->documentAmount($i, $currencyFilter)), 2),
                'count' => $group->count(),
            ])
            ->values()
            ->sortBy('date')
            ->values()
            ->all();

        $dailySales = $this->fillDailyGaps($dailySales, $days);
        $dailyPurchases = $this->fillDailyGaps($dailyPurchases, $days);

        $alerts = [];
        if ($lowStock > 0) {
            $warehouseHint = count($lowStockWarehouseNames) > 0
                ? ' ('.implode('، ', array_slice($lowStockWarehouseNames, 0, 3))
                    .(count($lowStockWarehouseNames) > 3 ? '…' : '').')'
                : '';
            $alerts[] = [
                'type' => 'warning',
                'code' => 'low_stock',
                'title' => 'تنبيه مخزون',
                'body' => "{$lowStock} تنبيه تحت حد إعادة الطلب{$warehouseHint}",
                'href' => '/warehouse?tab=alerts',
                'warehouse_names' => $lowStockWarehouseNames,
            ];
        }
        if ($receivables > 0) {
            $alerts[] = [
                'type' => 'info',
                'code' => 'receivables',
                'title' => 'ذمم مدينة',
                'body' => 'يوجد أرصدة مستحقة على العملاء',
                'href' => '/partners?tab=customers',
            ];
        }
        if ($payables > 0) {
            $alerts[] = [
                'type' => 'info',
                'code' => 'payables',
                'title' => 'ذمم دائنة',
                'body' => 'يوجد أرصدة مستحقة للموردين',
                'href' => '/partners?tab=suppliers',
            ];
        }
        $draftCount = JournalEntry::query()->where('status', 'draft')->count();
        if ($draftCount > 0) {
            $alerts[] = [
                'type' => 'info',
                'code' => 'draft_journals',
                'title' => 'قيود مسودة',
                'body' => "{$draftCount} قيد بانتظار الترحيل",
                'href' => '/journal-entries',
            ];
        }

        if (AppNotification::query()->count() === 0) {
            AppNotification::query()->create([
                'type' => 'info',
                'title' => 'مرحباً بك في Syna Co',
                'body' => 'لوحة الإشعارات جاهزة — ستظهر هنا تنبيهات المخزون والذمم.',
                'data' => ['href' => '/'],
            ]);
        }

        $baseTotals = [
            'currency' => $baseCurrency,
            'revenue' => round($revenue, 2),
            'expense' => round($expense, 2),
            'net_income' => round($revenue - $expense, 2),
            'receivables' => round(max($receivables, 0), 2),
            'payables' => round(max($payables, 0), 2),
            'month_sales' => round($monthSales, 2),
            'month_purchases' => round($monthPurchases, 2),
        ];

        $byCurrency = $currencyFilter === null
            ? $this->buildByCurrency($branchId)
            : null;

        return response()->json([
            'data' => [
                'company_name' => Setting::getValue('company_name', 'Syna Co'),
                'accounts_count' => Account::query()->count(),
                'journal_entries_count' => JournalEntry::query()->count(),
                'posted_entries_count' => JournalEntry::query()->where('status', 'posted')->count(),
                'draft_entries_count' => $draftCount,
                'revenue' => $baseTotals['revenue'],
                'expense' => $baseTotals['expense'],
                'net_income' => $baseTotals['net_income'],
                'currency' => $displayCurrency,
                'base_currency' => $baseCurrency,
                'filter_branch_id' => $branchId,
                'filter_currency' => $currencyFilter,
                'receivables' => $baseTotals['receivables'],
                'payables' => $baseTotals['payables'],
                'month_sales' => $baseTotals['month_sales'],
                'month_purchases' => $baseTotals['month_purchases'],
                'base_totals' => $currencyFilter === null ? $baseTotals : null,
                'by_currency' => $byCurrency,
                'daily_sales' => $dailySales,
                'daily_purchases' => $dailyPurchases,
                'customers_count' => Customer::query()->count(),
                'suppliers_count' => Supplier::query()->count(),
                'products_count' => Product::query()->count(),
                'low_stock_count' => $lowStock,
                'alerts' => $alerts,
            ],
        ]);
    }

    /**
     * Native-currency stats per currency when «كل العملات» is selected.
     *
     * @return list<array{
     *   currency: string,
     *   revenue: float,
     *   expense: float,
     *   net_income: float,
     *   receivables: float,
     *   payables: float,
     *   month_sales: float,
     *   month_purchases: float
     * }>
     */
    protected function buildByCurrency(?int $branchId): array
    {
        $sales = $this->applyDocFilters(
            SalesInvoice::query()->where('status', 'posted'),
            $branchId,
            null
        )->get();

        $purchases = $this->applyDocFilters(
            PurchaseInvoice::query()->where('status', 'posted'),
            $branchId,
            null
        )->get();

        $month = now()->month;
        $year = now()->year;

        $activeCodes = Currency::query()
            ->where('is_active', true)
            ->pluck('code')
            ->map(fn ($c) => strtoupper((string) $c));

        $codes = collect()
            ->merge($activeCodes)
            ->merge($sales->pluck('currency')->map(fn ($c) => strtoupper((string) $c)))
            ->merge($purchases->pluck('currency')->map(fn ($c) => strtoupper((string) $c)))
            ->filter()
            ->unique()
            ->values();

        $baseCurrency = strtoupper((string) Setting::getValue('currency', 'USD'));
        $codes = $codes->sort(function (string $a, string $b) use ($baseCurrency) {
            if ($a === $baseCurrency) {
                return -1;
            }
            if ($b === $baseCurrency) {
                return 1;
            }

            return strcmp($a, $b);
        })->values();

        return $codes->map(function (string $code) use ($sales, $purchases, $month, $year) {
            $salesFor = $this->filterByCurrency($sales, $code);
            $purchasesFor = $this->filterByCurrency($purchases, $code);

            $revenue = (float) $salesFor->sum(fn (SalesInvoice $i) => (float) $i->total);
            $expense = (float) $purchasesFor->sum(fn (PurchaseInvoice $i) => (float) $i->total);
            $receivables = (float) $salesFor->sum(
                fn (SalesInvoice $i) => max(0, (float) $i->total - (float) $i->paid_amount)
            );
            $payables = (float) $purchasesFor->sum(
                fn (PurchaseInvoice $i) => max(0, (float) $i->total - (float) $i->paid_amount)
            );
            $monthSales = (float) $salesFor
                ->filter(fn (SalesInvoice $i) => (int) $i->invoice_date->month === $month
                    && (int) $i->invoice_date->year === $year)
                ->sum(fn (SalesInvoice $i) => (float) $i->total);
            $monthPurchases = (float) $purchasesFor
                ->filter(fn (PurchaseInvoice $i) => (int) $i->invoice_date->month === $month
                    && (int) $i->invoice_date->year === $year)
                ->sum(fn (PurchaseInvoice $i) => (float) $i->total);

            return [
                'currency' => $code,
                'revenue' => round($revenue, 2),
                'expense' => round($expense, 2),
                'net_income' => round($revenue - $expense, 2),
                'receivables' => round($receivables, 2),
                'payables' => round($payables, 2),
                'month_sales' => round($monthSales, 2),
                'month_purchases' => round($monthPurchases, 2),
            ];
        })->all();
    }

    /**
     * @param  Collection<int, SalesInvoice|PurchaseInvoice>  $rows
     * @return Collection<int, SalesInvoice|PurchaseInvoice>
     */
    protected function filterByCurrency(Collection $rows, string $code): Collection
    {
        return $rows->filter(
            fn (SalesInvoice|PurchaseInvoice $inv) => strtoupper((string) $inv->currency) === $code
        )->values();
    }

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @return Builder<\Illuminate\Database\Eloquent\Model>
     */
    protected function applyDocFilters(Builder $query, ?int $branchId, ?string $currency): Builder
    {
        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }
        if ($currency !== null) {
            $query->where('currency', $currency);
        }

        return $query;
    }

    /**
     * Selected currency: totals in that currency (document total).
     * All currencies: convert to base using base_amount / exchange_rate.
     */
    protected function documentAmount(SalesInvoice|PurchaseInvoice $inv, ?string $currencyFilter): float
    {
        if ($currencyFilter !== null) {
            return (float) $inv->total;
        }

        if ($inv->base_amount !== null && $inv->base_amount !== '') {
            return (float) $inv->base_amount;
        }

        $rate = (float) ($inv->exchange_rate ?: 1);

        return round((float) $inv->total * $rate, 2);
    }

    protected function unpaidAmount(SalesInvoice|PurchaseInvoice $inv, ?string $currencyFilter): float
    {
        $unpaid = max(0, (float) $inv->total - (float) $inv->paid_amount);
        if ($currencyFilter !== null) {
            return $unpaid;
        }

        $rate = (float) ($inv->exchange_rate ?: 1);

        return round($unpaid * $rate, 2);
    }

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     */
    protected function sumDocumentAmounts(Builder $query, ?string $currencyFilter): float
    {
        return (float) $query->get()->sum(
            fn (SalesInvoice|PurchaseInvoice $inv) => $this->documentAmount($inv, $currencyFilter)
        );
    }

    protected function fillDailyGaps(array $rows, int $days): array
    {
        $map = collect($rows)->keyBy('date');
        $result = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->toDateString();
            $result[] = $map->get($date, ['date' => $date, 'total' => 0, 'count' => 0]);
        }

        return $result;
    }
}
