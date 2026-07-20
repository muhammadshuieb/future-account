<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AppNotification;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Models\Setting;
use App\Models\StockLevel;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function summary(): JsonResponse
    {
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

        $receivables = (float) SalesInvoice::query()
            ->where('status', 'posted')
            ->get()
            ->sum(function (SalesInvoice $inv) {
                $rate = (float) ($inv->exchange_rate ?: 1);
                $unpaid = max(0, (float) $inv->total - (float) $inv->paid_amount);

                return round($unpaid * $rate, 2);
            });

        $payables = (float) PurchaseInvoice::query()
            ->where('status', 'posted')
            ->get()
            ->sum(function (PurchaseInvoice $inv) {
                $rate = (float) ($inv->exchange_rate ?: 1);
                $unpaid = max(0, (float) $inv->total - (float) $inv->paid_amount);

                return round($unpaid * $rate, 2);
            });

        $lowStock = StockLevel::query()
            ->join('products', 'products.id', '=', 'stock_levels.product_id')
            ->whereColumn('stock_levels.quantity', '<=', 'products.reorder_level')
            ->where('products.reorder_level', '>', 0)
            ->count();

        $monthSales = (float) SalesInvoice::query()
            ->where('status', 'posted')
            ->whereMonth('invoice_date', now()->month)
            ->whereYear('invoice_date', now()->year)
            ->get()
            ->sum(fn (SalesInvoice $i) => (float) ($i->base_amount ?: $i->total));

        $monthPurchases = (float) PurchaseInvoice::query()
            ->where('status', 'posted')
            ->whereMonth('invoice_date', now()->month)
            ->whereYear('invoice_date', now()->year)
            ->get()
            ->sum(fn (PurchaseInvoice $i) => (float) ($i->base_amount ?: $i->total));

        $days = (int) request()->query('days', 7);
        $days = in_array($days, [7, 30], true) ? $days : 7;
        $fromDate = now()->subDays($days - 1)->startOfDay();

        $dailySales = SalesInvoice::query()
            ->where('status', 'posted')
            ->where('invoice_date', '>=', $fromDate->toDateString())
            ->get()
            ->groupBy(fn (SalesInvoice $i) => $i->invoice_date->toDateString())
            ->map(fn ($group, $date) => [
                'date' => $date,
                'total' => round($group->sum(fn (SalesInvoice $i) => (float) ($i->base_amount ?: $i->total)), 2),
                'count' => $group->count(),
            ])
            ->values()
            ->sortBy('date')
            ->values()
            ->all();

        $dailyPurchases = PurchaseInvoice::query()
            ->where('status', 'posted')
            ->where('invoice_date', '>=', $fromDate->toDateString())
            ->get()
            ->groupBy(fn (PurchaseInvoice $i) => $i->invoice_date->toDateString())
            ->map(fn ($group, $date) => [
                'date' => $date,
                'total' => round($group->sum(fn (PurchaseInvoice $i) => (float) ($i->base_amount ?: $i->total)), 2),
                'count' => $group->count(),
            ])
            ->values()
            ->sortBy('date')
            ->values()
            ->all();

        // Fill missing dates with zero
        $dailySales = $this->fillDailyGaps($dailySales, $days);
        $dailyPurchases = $this->fillDailyGaps($dailyPurchases, $days);

        $alerts = [];
        if ($lowStock > 0) {
            $alerts[] = ['type' => 'warning', 'title' => 'تنبيه مخزون', 'body' => "{$lowStock} صنف تحت حد إعادة الطلب"];
        }
        if ($receivables > 0) {
            $alerts[] = ['type' => 'info', 'title' => 'ذمم مدينة', 'body' => 'يوجد أرصدة مستحقة على العملاء'];
        }
        $draftCount = JournalEntry::query()->where('status', 'draft')->count();
        if ($draftCount > 0) {
            $alerts[] = ['type' => 'info', 'title' => 'قيود مسودة', 'body' => "{$draftCount} قيد بانتظار الترحيل"];
        }

        // Seed a couple of system notifications if empty (stub center)
        if (AppNotification::query()->count() === 0) {
            AppNotification::query()->create([
                'type' => 'info',
                'title' => 'مرحباً بك في Syna Co',
                'body' => 'لوحة الإشعارات جاهزة — ستظهر هنا تنبيهات المخزون والذمم.',
            ]);
        }

        return response()->json([
            'data' => [
                'company_name' => Setting::getValue('company_name', 'Syna Co'),
                'accounts_count' => Account::query()->count(),
                'journal_entries_count' => JournalEntry::query()->count(),
                'posted_entries_count' => JournalEntry::query()->where('status', 'posted')->count(),
                'draft_entries_count' => $draftCount,
                'revenue' => round($revenue, 2),
                'expense' => round($expense, 2),
                'net_income' => round($revenue - $expense, 2),
                'currency' => Setting::getValue('currency', 'SYP'),
                'receivables' => round(max($receivables, 0), 2),
                'payables' => round(max($payables, 0), 2),
                'month_sales' => round($monthSales, 2),
                'month_purchases' => round($monthPurchases, 2),
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
