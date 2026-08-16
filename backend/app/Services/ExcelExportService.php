<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Bank;
use App\Models\Branch;
use App\Models\CashBox;
use App\Models\CashTransfer;
use App\Models\Category;
use App\Models\Company;
use App\Models\Currency;
use App\Models\CurrencyExchange;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\ExchangeRate;
use App\Models\JournalDetail;
use App\Models\JournalEntry;
use App\Models\LeaveRequest;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceLine;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\PurchaseReturn;
use App\Models\Receipt;
use App\Models\SalaryRecord;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Models\SalesOrder;
use App\Models\SalesQuote;
use App\Models\SalesReturn;
use App\Models\Setting;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseTransfer;
use App\Services\Excel\ExcelWorkbook;
use App\Support\WarehouseAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExcelExportService
{
    public function __construct(protected ReportService $reports) {}

    /** @return array<string, string> module => permission */
    public static function modulePermissions(): array
    {
        return [
            'sales-quotes' => 'sales.view',
            'sales-orders' => 'sales.view',
            'sales-invoices' => 'sales.view',
            'sales-returns' => 'sales.view',
            'receipts' => 'sales.view',
            'purchase-requests' => 'purchases.view',
            'purchase-orders' => 'purchases.view',
            'purchase-invoices' => 'purchases.view',
            'purchase-returns' => 'purchases.view',
            'supplier-payments' => 'purchases.view',
            'customers' => 'customers.view',
            'suppliers' => 'suppliers.view',
            'products' => 'warehouse.view',
            'categories' => 'warehouse.view',
            'units' => 'warehouse.view',
            'warehouses' => 'warehouse.view',
            'stock-levels' => 'warehouse.view',
            'stock-movements' => 'warehouse.view',
            'warehouse-transfers' => 'warehouse.view',
            'cash-boxes' => 'cash.view',
            'banks' => 'cash.view',
            'cash-transfers' => 'cash.view',
            'currency-exchanges' => 'cash.view',
            'accounts' => 'accounts.view',
            'journal-entries' => 'journals.view',
            'employees' => 'hr.view',
            'attendances' => 'hr.view',
            'leave-requests' => 'hr.view',
            'salary-records' => 'hr.view',
            'audit-logs' => 'settings.manage',
        ];
    }

    public function filename(string $module): string
    {
        return 'syna-'.$module.'-'.now()->format('Y-m-d').'.xlsx';
    }

    public function downloadModule(string $module, Request $request): StreamedResponse
    {
        $book = new ExcelWorkbook('Syna '.$module);
        $this->fillModule($book, $module, $request);

        return $this->streamDownload($book, $this->filename($module));
    }

    public function downloadReport(string $type, Request $request): StreamedResponse
    {
        $book = new ExcelWorkbook('Syna report '.$type);
        $this->fillReport($book, $type, $request);

        return $this->streamDownload($book, $this->filename('report-'.$type));
    }

    public function downloadFullArchive(): StreamedResponse
    {
        $book = $this->buildFullArchive();

        return $this->streamDownload($book, $this->filename('full-archive'));
    }

    /**
     * Write full archive next to a backup dump (same stamp prefix).
     *
     * @return array{filename: string, path: string, size: int, size_human: string}
     */
    public function saveFullArchiveBeside(string $dumpFilename, string $directory): array
    {
        $base = pathinfo($dumpFilename, PATHINFO_FILENAME);
        $filename = $base.'.xlsx';
        $path = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$filename;
        $book = $this->buildFullArchive();
        $book->save($path);

        $size = File::size($path);

        return [
            'filename' => $filename,
            'path' => $path,
            'size' => $size,
            'size_human' => $this->humanSize($size),
        ];
    }

    public function buildFullArchive(): ExcelWorkbook
    {
        $book = new ExcelWorkbook('Syna full archive');

        $book->addSheet('الشركات', ['المعرف', 'الكود', 'الاسم', 'الاسم الإنجليزي', 'الرقم الضريبي', 'العملة', 'نشط'],
            Company::query()->orderBy('id')->get()->map(fn (Company $c) => [
                $c->id, $c->code, $c->name, $c->name_en, $c->tax_number, $c->currency, $c->is_active ? '1' : '0',
            ])->all());

        $book->addSheet('الفروع', ['المعرف', 'الشركة', 'الاسم', 'الكود', 'نشط'],
            Branch::query()->with('company')->orderBy('id')->get()->map(fn (Branch $b) => [
                $b->id, $b->company?->name, $b->name, $b->code, $b->is_active ? '1' : '0',
            ])->all());

        $book->addSheet('المستخدمون', ['المعرف', 'الاسم', 'اسم المستخدم', 'البريد', 'الجوال', 'نشط', 'الأدوار'],
            User::query()->with('roles')->orderBy('id')->get()->map(fn (User $u) => [
                $u->id, $u->name, $u->username, $u->email, $u->mobile,
                $u->is_active ? '1' : '0', $u->roles->pluck('name')->implode(', '),
            ])->all());

        $book->addSheet('الحسابات', ['المعرف', 'الرمز', 'الاسم', 'النوع', 'الطبيعة', 'أب', 'مجموعة', 'نشط'],
            Account::query()->orderBy('code')->get()->map(fn (Account $a) => [
                $a->id, $a->code, $a->name, $a->type, $a->nature, $a->parent_id,
                $a->is_group ? '1' : '0', $a->is_active ? '1' : '0',
            ])->all());

        $book->addSheet('العملاء', ['المعرف', 'الكود', 'الاسم', 'الهاتف', 'البريد', 'العنوان', 'حد الائتمان', 'نشط'],
            Customer::query()->orderBy('id')->get()->map(fn (Customer $c) => [
                $c->id, $c->code, $c->name, $c->phone, $c->email, $c->address,
                $c->credit_limit ?? '', $c->is_active ? '1' : '0',
            ])->all());

        $book->addSheet('الموردون', ['المعرف', 'الكود', 'الاسم', 'الهاتف', 'البريد', 'العنوان', 'حد الائتمان', 'نشط'],
            Supplier::query()->orderBy('id')->get()->map(fn (Supplier $s) => [
                $s->id, $s->code, $s->name, $s->phone, $s->email, $s->address,
                $s->credit_limit ?? '', $s->is_active ? '1' : '0',
            ])->all());

        $book->addSheet('التصنيفات', ['المعرف', 'الاسم', 'أب'],
            Category::query()->orderBy('id')->get()->map(fn (Category $c) => [
                $c->id, $c->name, $c->parent_id,
            ])->all());

        $book->addSheet('الوحدات', ['المعرف', 'الاسم', 'الرمز'],
            Unit::query()->orderBy('id')->get()->map(fn (Unit $u) => [
                $u->id, $u->name, $u->symbol ?? '',
            ])->all());

        $book->addSheet('المستودعات', ['المعرف', 'الاسم', 'الكود', 'الفرع', 'نشط'],
            Warehouse::query()->with('branch')->orderBy('id')->get()->map(fn (Warehouse $w) => [
                $w->id, $w->name, $w->code, $w->branch?->name, $w->is_active ? '1' : '0',
            ])->all());

        $book->addSheet('الأصناف', ['المعرف', 'الكود', 'الاسم', 'الماركة', 'الموديل', 'التصنيف', 'الوحدة', 'سعر البيع', 'التكلفة', 'باركود', 'نشط'],
            Product::query()->with(['category', 'unit'])->orderBy('id')->get()->map(fn (Product $p) => [
                $p->id, $p->sku, $p->name, $p->brand, $p->model, $p->category?->name, $p->unit?->name,
                $p->sale_price, $p->cost_price, $p->barcode, $p->is_active ? '1' : '0',
            ])->all());

        $book->addSheet('أرصدة المخزون', ['المعرف', 'الصنف', 'الماركة', 'الموديل', 'المستودع', 'الكمية', 'رقم الدفعة'],
            StockLevel::query()->with(['product', 'warehouse'])->orderBy('id')->get()->map(fn (StockLevel $s) => [
                $s->id, $s->product?->name, $s->product?->brand, $s->product?->model,
                $s->warehouse?->name, $s->quantity, $s->batch_no,
            ])->all());

        $book->addSheet('فواتير المبيعات', [
            'المعرف', 'الرقم', 'التاريخ', 'العميل', 'الحالة', 'نوع الدفع', 'العملة', 'سعر الصرف', 'المجموع', 'الحسم', 'الضريبة', 'الإجمالي', 'المدفوع', 'الفرع',
        ], SalesInvoice::query()->with('customer')->orderBy('id')->get()->map(fn (SalesInvoice $i) => [
            $i->id, $i->invoice_number, optional($i->invoice_date)?->format('Y-m-d'), $i->customer?->name,
            $i->status, $i->payment_type, $i->currency, $i->exchange_rate, $i->subtotal, $i->discount_amount, $i->tax_amount, $i->total, $i->paid_amount, $i->branch_id,
        ])->all());

        $book->addSheet('بنود فواتير المبيعات', [
            'المعرف', 'الفاتورة', 'الصنف', 'الماركة', 'الموديل', 'الكمية', 'السعر', 'الضريبة %', 'الإجمالي',
        ], SalesInvoiceLine::query()->with('product')->orderBy('id')->get()->map(fn (SalesInvoiceLine $l) => [
            $l->id, $l->sales_invoice_id, $l->product?->name, $l->product?->brand, $l->product?->model,
            $l->quantity, $l->unit_price, $l->tax_rate, $l->line_total,
        ])->all());

        $book->addSheet('فواتير المشتريات', [
            'المعرف', 'الرقم', 'التاريخ', 'المورد', 'الحالة', 'نوع الدفع', 'العملة', 'سعر الصرف', 'المجموع', 'الضريبة', 'جمارك', 'أجور نقل', 'مخالفات', 'أجور أخرى', 'الإجمالي', 'المدفوع',
        ], PurchaseInvoice::query()->with('supplier')->orderBy('id')->get()->map(fn (PurchaseInvoice $i) => [
            $i->id, $i->invoice_number, optional($i->invoice_date)?->format('Y-m-d'), $i->supplier?->name,
            $i->status, $i->payment_type, $i->currency, $i->exchange_rate, $i->subtotal, $i->tax_amount,
            $i->customs_amount, $i->transport_fees, $i->fines_amount, $i->other_fees,
            $i->total, $i->paid_amount,
        ])->all());

        $book->addSheet('بنود فواتير المشتريات', [
            'المعرف', 'الفاتورة', 'الصنف', 'الماركة', 'الموديل', 'الكمية', 'السعر', 'الضريبة %', 'الإجمالي',
        ], PurchaseInvoiceLine::query()->with('product')->orderBy('id')->get()->map(fn (PurchaseInvoiceLine $l) => [
            $l->id, $l->purchase_invoice_id, $l->product?->name, $l->product?->brand, $l->product?->model,
            $l->quantity, $l->unit_price, $l->tax_rate, $l->line_total,
        ])->all());

        $book->addSheet('المقبوضات', [
            'المعرف', 'الرقم', 'التاريخ', 'العميل', 'المبلغ', 'العملة', 'الحالة', 'فاتورة المبيعات',
        ], Receipt::query()->with('customer')->orderBy('id')->get()->map(fn (Receipt $r) => [
            $r->id, $r->receipt_number, optional($r->receipt_date)?->format('Y-m-d'), $r->customer?->name,
            $r->amount, $r->currency, $r->status, $r->sales_invoice_id,
        ])->all());

        $book->addSheet('المدفوعات', [
            'المعرف', 'الرقم', 'التاريخ', 'المورد', 'المبلغ', 'العملة', 'الحالة', 'فاتورة المشتريات',
        ], SupplierPayment::query()->with('supplier')->orderBy('id')->get()->map(fn (SupplierPayment $p) => [
            $p->id, $p->payment_number, optional($p->payment_date)?->format('Y-m-d'), $p->supplier?->name,
            $p->amount, $p->currency, $p->status, $p->purchase_invoice_id,
        ])->all());

        $book->addSheet('القيود', [
            'المعرف', 'الرقم', 'التاريخ', 'البيان', 'الحالة', 'المرجع',
        ], JournalEntry::query()->orderBy('id')->get()->map(fn (JournalEntry $j) => [
            $j->id, $j->entry_number, optional($j->entry_date)?->format('Y-m-d'), $j->description, $j->status, $j->reference,
        ])->all());

        $book->addSheet('تفاصيل القيود', [
            'المعرف', 'القيد', 'الحساب', 'مدين', 'دائن', 'البيان',
        ], JournalDetail::query()->with('account')->orderBy('id')->get()->map(fn (JournalDetail $d) => [
            $d->id, $d->journal_entry_id, $d->account?->code.' — '.$d->account?->name,
            $d->debit, $d->credit, $d->memo,
        ])->all());

        $book->addSheet('الصناديق', ['المعرف', 'الكود', 'الاسم', 'العملة', 'الرصيد الافتتاحي', 'نشط'],
            CashBox::query()->orderBy('id')->get()->map(fn (CashBox $c) => [
                $c->id, $c->code, $c->name, $c->currency, $c->opening_balance, $c->is_active ? '1' : '0',
            ])->all());

        $book->addSheet('البنوك', ['المعرف', 'الكود', 'الاسم', 'رقم الحساب', 'العملة', 'الرصيد الافتتاحي', 'نشط'],
            Bank::query()->orderBy('id')->get()->map(fn (Bank $b) => [
                $b->id, $b->code, $b->name, $b->account_number, $b->currency, $b->opening_balance, $b->is_active ? '1' : '0',
            ])->all());

        $book->addSheet('صرافة العملات', [
            'المعرف', 'الرقم', 'التاريخ', 'من', 'إلى', 'المبلغ المصدر', 'المبلغ الناتج', 'سعر الصرف', 'الحالة',
        ], CurrencyExchange::query()->orderBy('id')->get()->map(fn (CurrencyExchange $e) => [
            $e->id,
            $e->exchange_number,
            optional($e->exchange_date)?->format('Y-m-d'),
            $e->source_currency,
            $e->target_currency,
            $e->source_amount,
            $e->target_amount,
            $e->exchange_rate,
            $e->status,
        ])->all());

        $book->addSheet('العملات', ['المعرف', 'الرمز', 'الاسم', 'الرمز المختصر', 'نشط'],
            Currency::query()->orderBy('id')->get()->map(fn (Currency $c) => [
                $c->id, $c->code, $c->name, $c->symbol, $c->is_active ? '1' : '0',
            ])->all());

        $book->addSheet('أسعار الصرف', ['المعرف', 'من', 'إلى', 'السعر', 'التاريخ', 'ملاحظات'],
            ExchangeRate::query()->orderByDesc('rate_date')->orderBy('id')->get()->map(fn (ExchangeRate $r) => [
                $r->id, $r->from_currency, $r->to_currency, $r->rate,
                optional($r->rate_date)?->format('Y-m-d'), $r->notes,
            ])->all());

        $secretKeys = Setting::SECRET_KEYS;
        $book->addSheet('الإعدادات', ['المفتاح', 'القيمة', 'المجموعة', 'النوع'],
            Setting::query()->orderBy('key')->get()
                ->filter(fn (Setting $s) => ! in_array($s->key, $secretKeys, true))
                ->map(fn (Setting $s) => [$s->key, $s->value, $s->group, $s->type])
                ->values()
                ->all());

        return $book;
    }

    protected function fillModule(ExcelWorkbook $book, string $module, Request $request): void
    {
        match ($module) {
            'sales-quotes' => $this->sheetSalesQuotes($book, $request),
            'sales-orders' => $this->sheetSalesOrders($book, $request),
            'sales-invoices' => $this->sheetSalesInvoices($book, $request),
            'sales-returns' => $this->sheetSalesReturns($book, $request),
            'receipts' => $this->sheetReceipts($book, $request),
            'purchase-requests' => $this->sheetPurchaseRequests($book, $request),
            'purchase-orders' => $this->sheetPurchaseOrders($book, $request),
            'purchase-invoices' => $this->sheetPurchaseInvoices($book, $request),
            'purchase-returns' => $this->sheetPurchaseReturns($book, $request),
            'supplier-payments' => $this->sheetSupplierPayments($book, $request),
            'customers' => $this->sheetCustomers($book, $request),
            'suppliers' => $this->sheetSuppliers($book, $request),
            'products' => $this->sheetProducts($book, $request),
            'categories' => $book->addSheet('التصنيفات', ['المعرف', 'الاسم', 'أب'],
                Category::query()->orderBy('id')->get()->map(fn ($c) => [$c->id, $c->name, $c->parent_id])->all()),
            'units' => $book->addSheet('الوحدات', ['المعرف', 'الاسم', 'الرمز'],
                Unit::query()->orderBy('id')->get()->map(fn ($u) => [$u->id, $u->name, $u->symbol ?? ''])->all()),
            'warehouses' => $book->addSheet('المستودعات', ['المعرف', 'الاسم', 'الكود', 'الفرع', 'نشط'],
                $this->scopedWarehouseQuery()->with('branch')->orderBy('id')->get()->map(fn ($w) => [
                    $w->id, $w->name, $w->code, $w->branch?->name, $w->is_active ? '1' : '0',
                ])->all()),
            'stock-levels' => $this->sheetStockLevels($book, $request),
            'stock-movements' => $this->sheetStockMovements($book, $request),
            'warehouse-transfers' => $this->sheetWarehouseTransfers($book, $request),
            'cash-boxes' => $book->addSheet('الصناديق', ['المعرف', 'الكود', 'الاسم', 'العملة', 'الرصيد الافتتاحي', 'نشط'],
                CashBox::query()->orderBy('id')->get()->map(fn ($c) => [
                    $c->id, $c->code, $c->name, $c->currency, $c->opening_balance, $c->is_active ? '1' : '0',
                ])->all()),
            'banks' => $book->addSheet('البنوك', ['المعرف', 'الكود', 'الاسم', 'رقم الحساب', 'العملة', 'الرصيد الافتتاحي', 'نشط'],
                Bank::query()->orderBy('id')->get()->map(fn ($b) => [
                    $b->id, $b->code, $b->name, $b->account_number, $b->currency, $b->opening_balance, $b->is_active ? '1' : '0',
                ])->all()),
            'cash-transfers' => $this->sheetCashTransfers($book, $request),
            'currency-exchanges' => $this->sheetCurrencyExchanges($book, $request),
            'accounts' => $book->addSheet('الحسابات', ['المعرف', 'الرمز', 'الاسم', 'النوع', 'الطبيعة', 'أب', 'مجموعة', 'نشط'],
                Account::query()->orderBy('code')->get()->map(fn ($a) => [
                    $a->id, $a->code, $a->name, $a->type, $a->nature, $a->parent_id, $a->is_group ? '1' : '0', $a->is_active ? '1' : '0',
                ])->all()),
            'journal-entries' => $this->sheetJournalEntries($book, $request),
            'employees' => $book->addSheet('الموظفون', ['المعرف', 'الرقم', 'الاسم', 'الوظيفة', 'القسم', 'الهاتف', 'الراتب الأساسي', 'نشط'],
                Employee::query()->orderBy('id')->get()->map(fn ($e) => [
                    $e->id, $e->employee_number, $e->name, $e->job_title, $e->department, $e->phone, $e->basic_salary, $e->is_active ? '1' : '0',
                ])->all()),
            'attendances' => $this->sheetAttendances($book, $request),
            'leave-requests' => $book->addSheet('الإجازات', ['المعرف', 'الموظف', 'من', 'إلى', 'النوع', 'الحالة'],
                LeaveRequest::query()->with('employee')->orderByDesc('id')->get()->map(fn ($l) => [
                    $l->id, $l->employee?->name, optional($l->from_date)?->format('Y-m-d'), optional($l->to_date)?->format('Y-m-d'),
                    $l->leave_type, $l->status,
                ])->all()),
            'salary-records' => $book->addSheet('الرواتب', ['المعرف', 'الموظف', 'الفترة', 'الأساسي', 'البدلات', 'الخصومات', 'الصافي', 'الحالة'],
                SalaryRecord::query()->with('employee')->orderByDesc('id')->get()->map(fn ($s) => [
                    $s->id, $s->employee?->name, $s->period, $s->basic_salary,
                    $s->allowances, $s->deductions, $s->net_salary, $s->status,
                ])->all()),
            'audit-logs' => $this->sheetAuditLogs($book, $request),
            default => throw new \InvalidArgumentException('وحدة تصدير غير معروفة: '.$module),
        };
    }

    protected function fillReport(ExcelWorkbook $book, string $type, Request $request): void
    {
        $from = $request->query('from');
        $to = $request->query('to');
        $asOf = $request->query('as_of') ?: $to;
        $branchId = $request->query('branch_id');

        match ($type) {
            'trial-balance' => $this->sheetTrialBalance($book, $asOf, $branchId),
            'income-statement' => $this->sheetIncomeStatement($book, $from, $to, $branchId),
            'balance-sheet' => $this->sheetBalanceSheet($book, $asOf, $branchId),
            'cash-flow' => $this->sheetGenericReport($book, 'التدفقات النقدية', $this->reports->cashFlow($from, $to, $branchId ? (int) $branchId : null)),
            'sales' => $this->sheetSalesReport($book, $from, $to, $branchId),
            'purchases' => $this->sheetPurchasesReport($book, $from, $to, $branchId),
            'inventory' => $this->sheetInventoryReport($book, $request),
            'profit' => $this->sheetGenericReport($book, 'مجمل الربح', $this->reports->profitReport($from, $to, $branchId ? (int) $branchId : null)),
            'tax' => $this->sheetGenericReport($book, 'الضريبة', $this->reports->taxReport($from, $to, $branchId ? (int) $branchId : null)),
            'general-ledger' => $this->sheetGeneralLedger($book, $request),
            'product-movement' => $this->sheetProductMovement($book, $request),
            'customer-statement' => $this->sheetPartnerStatement($book, 'customer', (int) $request->query('customer_id'), $from, $to),
            'supplier-statement' => $this->sheetPartnerStatement($book, 'supplier', (int) $request->query('supplier_id'), $from, $to),
            'branch-complete' => $this->sheetBranchComplete($book, $request),
            default => throw new \InvalidArgumentException('تقرير غير معروف: '.$type),
        };
    }

    protected function sheetSalesQuotes(ExcelWorkbook $book, Request $request): void
    {
        $q = SalesQuote::query()->with('customer')->orderByDesc('id');
        $this->applyDateFilter($q, 'quote_date', $request);
        $book->addSheet('عروض الأسعار', ['الرقم', 'التاريخ', 'العميل', 'العملة', 'الإجمالي', 'الحالة'],
            $q->get()->map(fn ($r) => [
                $r->quote_number, optional($r->quote_date)?->format('Y-m-d'), $r->customer?->name,
                $r->currency, $r->total, $r->status,
            ])->all());
    }

    protected function sheetSalesOrders(ExcelWorkbook $book, Request $request): void
    {
        $q = SalesOrder::query()->with('customer')->orderByDesc('id');
        $this->applyDateFilter($q, 'order_date', $request);
        $book->addSheet('أوامر البيع', ['الرقم', 'التاريخ', 'العميل', 'العملة', 'الإجمالي', 'الحالة'],
            $q->get()->map(fn ($r) => [
                $r->order_number, optional($r->order_date)?->format('Y-m-d'), $r->customer?->name,
                $r->currency, $r->total, $r->status,
            ])->all());
    }

    protected function sheetSalesInvoices(ExcelWorkbook $book, Request $request): void
    {
        $q = SalesInvoice::query()->with('customer')->orderByDesc('id');
        $this->applyDateFilter($q, 'invoice_date', $request);
        if ($request->filled('branch_id')) {
            $q->where('branch_id', $request->query('branch_id'));
        }
        if ($request->filled('currency')) {
            $q->where('currency', $request->query('currency'));
        }
        $book->addSheet('فواتير المبيعات', [
            'الرقم', 'التاريخ', 'العميل', 'نوع الدفع', 'العملة', 'سعر الصرف', 'المجموع', 'الحسم', 'الضريبة', 'الإجمالي', 'المدفوع', 'الحالة',
        ], $q->get()->map(fn ($r) => [
            $r->invoice_number, optional($r->invoice_date)?->format('Y-m-d'), $r->customer?->name,
            $r->payment_type, $r->currency, $r->exchange_rate, $r->subtotal, $r->discount_amount, $r->tax_amount, $r->total, $r->paid_amount, $r->status,
        ])->all());
    }

    protected function sheetSalesReturns(ExcelWorkbook $book, Request $request): void
    {
        $q = SalesReturn::query()->with('customer')->orderByDesc('id');
        $this->applyDateFilter($q, 'return_date', $request);
        $book->addSheet('مرتجعات المبيعات', ['الرقم', 'التاريخ', 'العميل', 'العملة', 'الإجمالي', 'الحالة'],
            $q->get()->map(fn ($r) => [
                $r->return_number, optional($r->return_date)?->format('Y-m-d'), $r->customer?->name,
                $r->currency, $r->total, $r->status,
            ])->all());
    }

    protected function sheetReceipts(ExcelWorkbook $book, Request $request): void
    {
        $q = Receipt::query()->with('customer')->orderByDesc('id');
        $this->applyDateFilter($q, 'receipt_date', $request);
        $book->addSheet('المقبوضات', ['الرقم', 'التاريخ', 'العميل', 'المبلغ', 'العملة', 'الحالة'],
            $q->get()->map(fn ($r) => [
                $r->receipt_number, optional($r->receipt_date)?->format('Y-m-d'), $r->customer?->name,
                $r->amount, $r->currency, $r->status,
            ])->all());
    }

    protected function sheetPurchaseRequests(ExcelWorkbook $book, Request $request): void
    {
        $q = PurchaseRequest::query()->orderByDesc('id');
        $this->applyDateFilter($q, 'request_date', $request);
        $book->addSheet('طلبات الشراء', ['الرقم', 'التاريخ', 'الحالة', 'الملاحظات'],
            $q->get()->map(fn ($r) => [
                $r->request_number, optional($r->request_date)?->format('Y-m-d'), $r->status, $r->notes,
            ])->all());
    }

    protected function sheetPurchaseOrders(ExcelWorkbook $book, Request $request): void
    {
        $q = PurchaseOrder::query()->with('supplier')->orderByDesc('id');
        $this->applyDateFilter($q, 'order_date', $request);
        $book->addSheet('أوامر الشراء', ['الرقم', 'التاريخ', 'المورد', 'العملة', 'الإجمالي', 'الحالة'],
            $q->get()->map(fn ($r) => [
                $r->order_number, optional($r->order_date)?->format('Y-m-d'), $r->supplier?->name,
                $r->currency, $r->total, $r->status,
            ])->all());
    }

    protected function sheetPurchaseInvoices(ExcelWorkbook $book, Request $request): void
    {
        $q = PurchaseInvoice::query()->with('supplier')->orderByDesc('id');
        $this->applyDateFilter($q, 'invoice_date', $request);
        if ($request->filled('branch_id')) {
            $q->where('branch_id', $request->query('branch_id'));
        }
        if ($request->filled('currency')) {
            $q->where('currency', $request->query('currency'));
        }
        $book->addSheet('فواتير المشتريات', [
            'الرقم', 'التاريخ', 'المورد', 'نوع الدفع', 'العملة', 'سعر الصرف', 'المجموع', 'الضريبة', 'جمارك', 'أجور نقل', 'مخالفات', 'أجور أخرى', 'الإجمالي', 'المدفوع', 'الحالة',
        ], $q->get()->map(fn ($r) => [
            $r->invoice_number, optional($r->invoice_date)?->format('Y-m-d'), $r->supplier?->name,
            $r->payment_type, $r->currency, $r->exchange_rate, $r->subtotal, $r->tax_amount,
            $r->customs_amount, $r->transport_fees, $r->fines_amount, $r->other_fees,
            $r->total, $r->paid_amount, $r->status,
        ])->all());
    }

    protected function sheetPurchaseReturns(ExcelWorkbook $book, Request $request): void
    {
        $q = PurchaseReturn::query()->with('supplier')->orderByDesc('id');
        $this->applyDateFilter($q, 'return_date', $request);
        $book->addSheet('مرتجعات المشتريات', ['الرقم', 'التاريخ', 'المورد', 'العملة', 'الإجمالي', 'الحالة'],
            $q->get()->map(fn ($r) => [
                $r->return_number, optional($r->return_date)?->format('Y-m-d'), $r->supplier?->name,
                $r->currency, $r->total, $r->status,
            ])->all());
    }

    protected function sheetSupplierPayments(ExcelWorkbook $book, Request $request): void
    {
        $q = SupplierPayment::query()->with('supplier')->orderByDesc('id');
        $this->applyDateFilter($q, 'payment_date', $request);
        $book->addSheet('المدفوعات', ['الرقم', 'التاريخ', 'المورد', 'المبلغ', 'العملة', 'الحالة'],
            $q->get()->map(fn ($r) => [
                $r->payment_number, optional($r->payment_date)?->format('Y-m-d'), $r->supplier?->name,
                $r->amount, $r->currency, $r->status,
            ])->all());
    }

    protected function sheetCustomers(ExcelWorkbook $book, Request $request): void
    {
        $q = Customer::query()->orderBy('id');
        if ($request->filled('q')) {
            $term = '%'.$request->query('q').'%';
            $q->where(fn ($qq) => $qq->where('name', 'ilike', $term)->orWhere('code', 'ilike', $term)->orWhere('phone', 'ilike', $term));
        }
        $book->addSheet('العملاء', ['الكود', 'الاسم', 'الهاتف', 'البريد', 'العنوان', 'حد الائتمان', 'نشط'],
            $q->get()->map(fn ($c) => [
                $c->code, $c->name, $c->phone, $c->email, $c->address, $c->credit_limit ?? '', $c->is_active ? '1' : '0',
            ])->all());
    }

    protected function sheetSuppliers(ExcelWorkbook $book, Request $request): void
    {
        $q = Supplier::query()->orderBy('id');
        if ($request->filled('q')) {
            $term = '%'.$request->query('q').'%';
            $q->where(fn ($qq) => $qq->where('name', 'ilike', $term)->orWhere('code', 'ilike', $term)->orWhere('phone', 'ilike', $term));
        }
        $book->addSheet('الموردون', ['الكود', 'الاسم', 'الهاتف', 'البريد', 'العنوان', 'حد الائتمان', 'نشط'],
            $q->get()->map(fn ($s) => [
                $s->code, $s->name, $s->phone, $s->email, $s->address, $s->credit_limit ?? '', $s->is_active ? '1' : '0',
            ])->all());
    }

    protected function scopedWarehouseQuery(): Builder
    {
        $query = Warehouse::query();
        if (auth()->user()) {
            WarehouseAccess::scopeWarehouseColumn($query, auth()->user(), 'id');
        }

        return $query;
    }

    protected function sheetProducts(ExcelWorkbook $book, Request $request): void
    {
        $q = Product::query()->with(['category', 'unit'])->orderBy('id');
        if (auth()->user() && WarehouseAccess::isScoped(auth()->user())) {
            $q->whereHas('stockLevels', fn ($query) => $query->whereIn(
                'warehouse_id',
                WarehouseAccess::warehouseIds(auth()->user()),
            ));
        }
        if ($request->filled('q')) {
            $term = '%'.$request->query('q').'%';
            $q->where(fn ($qq) => $qq
                ->where('name', 'ilike', $term)
                ->orWhere('sku', 'ilike', $term)
                ->orWhere('barcode', 'ilike', $term)
                ->orWhere('brand', 'ilike', $term)
                ->orWhere('model', 'ilike', $term));
        }
        $book->addSheet('الأصناف', ['الكود', 'الاسم', 'الماركة', 'الموديل', 'التصنيف', 'الوحدة', 'سعر البيع', 'التكلفة', 'باركود', 'نشط'],
            $q->get()->map(fn ($p) => [
                $p->sku, $p->name, $p->brand, $p->model, $p->category?->name, $p->unit?->name,
                $p->sale_price, $p->cost_price, $p->barcode, $p->is_active ? '1' : '0',
            ])->all());
    }

    protected function sheetStockLevels(ExcelWorkbook $book, Request $request): void
    {
        $q = StockLevel::query()->with(['product', 'warehouse'])->orderBy('id');
        if (auth()->user()) {
            WarehouseAccess::scopeWarehouseColumn($q, auth()->user());
        }
        if ($request->filled('warehouse_id')) {
            $q->where('warehouse_id', $request->query('warehouse_id'));
        }
        $book->addSheet('أرصدة المخزون', ['الصنف', 'الماركة', 'الموديل', 'المستودع', 'الكمية', 'رقم الدفعة'],
            $q->get()->map(fn ($s) => [
                $s->product?->name, $s->product?->brand, $s->product?->model,
                $s->warehouse?->name, $s->quantity, $s->batch_no,
            ])->all());
    }

    protected function sheetStockMovements(ExcelWorkbook $book, Request $request): void
    {
        $q = StockMovement::query()->with(['product', 'warehouse'])->orderByDesc('id');
        if (auth()->user()) {
            WarehouseAccess::scopeWarehouseColumn($q, auth()->user());
        }
        $this->applyDateFilter($q, 'movement_date', $request);
        if ($request->filled('warehouse_id')) {
            $q->where('warehouse_id', $request->query('warehouse_id'));
        }
        $book->addSheet('حركات المخزون', ['التاريخ', 'الصنف', 'الماركة', 'الموديل', 'المستودع', 'النوع', 'الكمية', 'المرجع'],
            $q->limit(10000)->get()->map(fn ($m) => [
                optional($m->movement_date ?? $m->created_at)?->format('Y-m-d'),
                $m->product?->name, $m->product?->brand, $m->product?->model,
                $m->warehouse?->name, $m->type ?? $m->movement_type,
                $m->quantity, $m->reference ?? $m->notes,
            ])->all());
    }

    protected function sheetWarehouseTransfers(ExcelWorkbook $book, Request $request): void
    {
        $q = WarehouseTransfer::query()->with(['fromWarehouse', 'toWarehouse'])->orderByDesc('id');
        if (auth()->user() && WarehouseAccess::isScoped(auth()->user())) {
            $ids = WarehouseAccess::warehouseIds(auth()->user());
            $q->whereIn('from_warehouse_id', $ids)->whereIn('to_warehouse_id', $ids);
        }
        $this->applyDateFilter($q, 'transfer_date', $request);
        $book->addSheet('تحويلات المستودعات', ['الرقم', 'التاريخ', 'من', 'إلى', 'الحالة'],
            $q->get()->map(fn ($t) => [
                $t->transfer_number, optional($t->transfer_date)?->format('Y-m-d'),
                $t->fromWarehouse?->name,
                $t->toWarehouse?->name,
                $t->status,
            ])->all());
    }

    protected function sheetCashTransfers(ExcelWorkbook $book, Request $request): void
    {
        $q = CashTransfer::query()->orderByDesc('id');
        $this->applyDateFilter($q, 'transfer_date', $request);
        $book->addSheet('تحويلات نقدية', ['الرقم', 'التاريخ', 'من', 'إلى', 'المبلغ', 'الحالة'],
            $q->get()->map(fn ($t) => [
                $t->transfer_number, optional($t->transfer_date)?->format('Y-m-d'),
                $t->from_type.'#'.$t->from_id, $t->to_type.'#'.$t->to_id,
                $t->amount, $t->status,
            ])->all());
    }

    protected function sheetCurrencyExchanges(ExcelWorkbook $book, Request $request): void
    {
        $q = CurrencyExchange::query()->orderByDesc('id');
        $this->applyDateFilter($q, 'exchange_date', $request);
        $book->addSheet('صرافة العملات', [
            'الرقم', 'التاريخ', 'من', 'إلى', 'المبلغ المصدر', 'المبلغ الناتج', 'سعر الصرف', 'الحالة',
        ], $q->get()->map(fn ($e) => [
            $e->exchange_number,
            optional($e->exchange_date)?->format('Y-m-d'),
            $e->source_currency,
            $e->target_currency,
            $e->source_amount,
            $e->target_amount,
            $e->exchange_rate,
            $e->status,
        ])->all());
    }

    protected function sheetJournalEntries(ExcelWorkbook $book, Request $request): void
    {
        $q = JournalEntry::query()->with('details.account')->orderByDesc('id');
        $this->applyDateFilter($q, 'entry_date', $request);
        $entries = $q->limit(5000)->get();
        $book->addSheet('القيود', ['الرقم', 'التاريخ', 'البيان', 'الحالة', 'المرجع'],
            $entries->map(fn ($j) => [
                $j->entry_number, optional($j->entry_date)?->format('Y-m-d'), $j->description, $j->status, $j->reference,
            ])->all());
        $detailRows = [];
        foreach ($entries as $j) {
            foreach ($j->details as $d) {
                $detailRows[] = [
                    $j->entry_number, $d->account?->code, $d->account?->name, $d->debit, $d->credit, $d->memo,
                ];
            }
        }
        $book->addSheet('تفاصيل القيود', ['رقم القيد', 'رمز الحساب', 'اسم الحساب', 'مدين', 'دائن', 'البيان'], $detailRows);
    }

    protected function sheetAttendances(ExcelWorkbook $book, Request $request): void
    {
        $q = Attendance::query()->with('employee')->orderByDesc('id');
        $this->applyDateFilter($q, 'attendance_date', $request);
        $book->addSheet('الحضور', ['الموظف', 'التاريخ', 'حضور', 'انصراف', 'الحالة'],
            $q->get()->map(fn ($a) => [
                $a->employee?->name,
                optional($a->attendance_date ?? $a->date)?->format('Y-m-d'),
                $a->check_in, $a->check_out, $a->status,
            ])->all());
    }

    protected function sheetAuditLogs(ExcelWorkbook $book, Request $request): void
    {
        $q = AuditLog::query()->with('user')->orderByDesc('id');
        $this->applyDateFilter($q, 'created_at', $request);
        $book->addSheet('سجل التدقيق', ['التاريخ', 'المستخدم', 'الإجراء', 'الكيان', 'المعرّف', 'عنوان IP'],
            $q->limit(20000)->get()->map(fn ($a) => [
                optional($a->created_at)?->format('Y-m-d H:i:s'),
                $a->user?->name, $a->action, $a->auditable_type ?? $a->entity_type,
                $a->auditable_id ?? $a->entity_id, $a->ip_address,
            ])->all());
    }

    protected function sheetTrialBalance(ExcelWorkbook $book, ?string $asOf, mixed $branchId = null): void
    {
        $data = $this->reports->trialBalance($asOf, $branchId ? (int) $branchId : null);
        $book->addSheet('ميزان المراجعة', ['الرمز', 'الحساب', 'النوع', 'مدين', 'دائن', 'الرصيد'],
            collect($data['rows'])->map(fn ($r) => [
                $r['code'], $r['name'], $r['type'], $r['debit'], $r['credit'], $r['balance'],
            ])->all());
    }

    protected function sheetIncomeStatement(ExcelWorkbook $book, ?string $from, ?string $to, mixed $branchId = null): void
    {
        $data = $this->reports->incomeStatement($from, $to, $branchId ? (int) $branchId : null);
        $rows = [];
        foreach (($data['revenue']['rows'] ?? []) as $a) {
            $rows[] = ['إيراد', $a['code'] ?? '', $a['name'] ?? '', $a['amount'] ?? 0];
        }
        foreach (($data['expense']['rows'] ?? []) as $a) {
            $rows[] = ['مصروف', $a['code'] ?? '', $a['name'] ?? '', $a['amount'] ?? 0];
        }
        $rows[] = ['صافي الدخل', '', '', $data['net_income'] ?? 0];
        $book->addSheet('قائمة الدخل', ['القسم', 'الرمز', 'الحساب', 'المبلغ'], $rows);
    }

    protected function sheetBalanceSheet(ExcelWorkbook $book, ?string $asOf, mixed $branchId = null): void
    {
        $data = $this->reports->balanceSheet($asOf, $branchId ? (int) $branchId : null);
        $rows = [];
        foreach (['assets' => 'أصول', 'liabilities' => 'خصوم', 'equity' => 'حقوق ملكية'] as $key => $label) {
            foreach ($data[$key] ?? [] as $r) {
                $rows[] = [$label, $r['code'], $r['name'], $r['balance']];
            }
        }
        $rows[] = ['صافي الدخل', '', '', $data['net_income'] ?? 0];
        $book->addSheet('الميزانية', ['القسم', 'الرمز', 'الحساب', 'الرصيد'], $rows);
    }

    protected function sheetSalesReport(ExcelWorkbook $book, ?string $from, ?string $to, mixed $branchId): void
    {
        $data = $this->reports->salesReport($from, $to, $branchId ? (int) $branchId : null);
        $rows = collect($data['rows'] ?? [])->map(fn ($i) => [
            $i->invoice_number,
            optional($i->invoice_date)?->format('Y-m-d'),
            $i->customer?->name,
            $i->currency,
            $i->total,
            $i->base_amount,
            $i->status,
        ])->all();
        $book->addSheet('تقرير المبيعات', ['الرقم', 'التاريخ', 'العميل', 'العملة', 'الإجمالي', 'بالأساسية', 'الحالة'], $rows);
        $book->addSheet('ملخص المبيعات', ['الحقل', 'القيمة'], [
            ['من', $data['from'] ?? ''],
            ['إلى', $data['to'] ?? ''],
            ['العدد', $data['count'] ?? 0],
            ['الإجمالي الأساسي', $data['total_base'] ?? 0],
        ]);
    }

    protected function sheetPurchasesReport(ExcelWorkbook $book, ?string $from, ?string $to, mixed $branchId): void
    {
        $data = $this->reports->purchaseReport($from, $to, $branchId ? (int) $branchId : null);
        $rows = collect($data['rows'] ?? [])->map(fn ($i) => [
            $i->invoice_number,
            optional($i->invoice_date)?->format('Y-m-d'),
            $i->supplier?->name,
            $i->currency,
            $i->total,
            $i->base_amount,
            $i->status,
        ])->all();
        $book->addSheet('تقرير المشتريات', ['الرقم', 'التاريخ', 'المورد', 'العملة', 'الإجمالي', 'بالأساسية', 'الحالة'], $rows);
        $book->addSheet('ملخص المشتريات', ['الحقل', 'القيمة'], [
            ['من', $data['from'] ?? ''],
            ['إلى', $data['to'] ?? ''],
            ['العدد', $data['count'] ?? 0],
            ['الإجمالي الأساسي', $data['total_base'] ?? 0],
        ]);
    }

    protected function sheetInventoryReport(ExcelWorkbook $book, Request $request): void
    {
        $branchId = $request->filled('branch_id') ? (int) $request->query('branch_id') : null;
        $warehouseId = $request->filled('warehouse_id') ? (int) $request->query('warehouse_id') : null;
        $data = $this->reports->inventoryReport($branchId, $warehouseId);
        $this->sheetGenericReport($book, 'تقرير المخزون', $data);
    }

    protected function sheetGeneralLedger(ExcelWorkbook $book, Request $request): void
    {
        $accountId = (int) $request->query('account_id');
        if ($accountId <= 0) {
            throw new \InvalidArgumentException('account_id مطلوب.');
        }
        $branchId = $request->filled('branch_id') ? (int) $request->query('branch_id') : null;
        $data = $this->reports->generalLedger($accountId, $request->query('from'), $request->query('to'), $branchId);
        $this->sheetGenericReport($book, 'دفتر الأستاذ', $data);
    }

    protected function sheetProductMovement(ExcelWorkbook $book, Request $request): void
    {
        $productId = (int) ($request->query('product_id') ?: $request->route('product'));
        if ($productId <= 0) {
            throw new \InvalidArgumentException('product_id مطلوب.');
        }
        $branchId = $request->filled('branch_id') ? (int) $request->query('branch_id') : null;
        $warehouseId = $request->filled('warehouse_id') ? (int) $request->query('warehouse_id') : null;
        $data = $this->reports->productMovement(
            $productId,
            $request->query('from'),
            $request->query('to'),
            $branchId,
            $warehouseId,
        );
        $this->sheetGenericReport($book, 'حركة صنف', $data);
    }

    protected function sheetBranchComplete(ExcelWorkbook $book, Request $request): void
    {
        $branchId = (int) $request->query('branch_id');
        if ($branchId <= 0) {
            throw new \InvalidArgumentException('branch_id مطلوب.');
        }
        $data = $this->reports->branchCompleteReport($branchId, $request->query('from'), $request->query('to'));
        $book->addSheet('تقرير الفرع الشامل', ['البند', 'القيمة'], [
            ['الفرع', ($data['branch']['code'] ?? '').' — '.($data['branch']['name'] ?? '')],
            ['من', $data['from'] ?? ''],
            ['إلى', $data['to'] ?? ''],
            ['عدد فواتير المبيعات', $data['sales']['count'] ?? 0],
            ['إجمالي المبيعات', $data['sales']['total'] ?? 0],
            ['عدد فواتير المشتريات', $data['purchases']['count'] ?? 0],
            ['إجمالي المشتريات', $data['purchases']['total'] ?? 0],
            ['مجمل الربح', $data['profit']['gross_profit'] ?? 0],
            ['الذمم المدينة (عليه)', $data['receivables'] ?? 0],
            ['الذمم الدائنة (علينا)', $data['payables'] ?? 0],
            ['قيمة المخزون', $data['stock_value'] ?? 0],
            ['ضريبة المخرجات', $data['tax']['output_vat'] ?? 0],
            ['ضريبة المدخلات', $data['tax']['input_vat'] ?? 0],
            ['صافي الضريبة', $data['tax']['net_vat'] ?? 0],
        ]);
    }

    protected function sheetPartnerStatement(ExcelWorkbook $book, string $kind, int $id, ?string $from, ?string $to): void
    {
        if ($id <= 0) {
            throw new \InvalidArgumentException($kind === 'customer' ? 'customer_id مطلوب.' : 'supplier_id مطلوب.');
        }
        if ($kind === 'customer') {
            $customer = Customer::query()->findOrFail($id);
            // Reuse statement endpoint logic via controller service if available — flatten balances from invoices/receipts.
            $invoices = SalesInvoice::query()->where('customer_id', $id)
                ->when($from, fn ($q) => $q->whereDate('invoice_date', '>=', $from))
                ->when($to, fn ($q) => $q->whereDate('invoice_date', '<=', $to))
                ->orderBy('invoice_date')->get();
            $receipts = Receipt::query()->where('customer_id', $id)
                ->when($from, fn ($q) => $q->whereDate('receipt_date', '>=', $from))
                ->when($to, fn ($q) => $q->whereDate('receipt_date', '<=', $to))
                ->orderBy('receipt_date')->get();
            $rows = [];
            foreach ($invoices as $inv) {
                $rows[] = [optional($inv->invoice_date)?->format('Y-m-d'), 'فاتورة', $inv->invoice_number, $inv->total, 0, $inv->currency];
            }
            foreach ($receipts as $rec) {
                $rows[] = [optional($rec->receipt_date)?->format('Y-m-d'), 'قبض', $rec->receipt_number, 0, $rec->amount, $rec->currency];
            }
            usort($rows, fn ($a, $b) => strcmp((string) $a[0], (string) $b[0]));
            $book->addSheet('كشف عميل '.$customer->name, ['التاريخ', 'النوع', 'المرجع', 'مدين', 'دائن', 'العملة'], $rows);
        } else {
            $supplier = Supplier::query()->findOrFail($id);
            $invoices = PurchaseInvoice::query()->where('supplier_id', $id)
                ->when($from, fn ($q) => $q->whereDate('invoice_date', '>=', $from))
                ->when($to, fn ($q) => $q->whereDate('invoice_date', '<=', $to))
                ->orderBy('invoice_date')->get();
            $payments = SupplierPayment::query()->where('supplier_id', $id)
                ->when($from, fn ($q) => $q->whereDate('payment_date', '>=', $from))
                ->when($to, fn ($q) => $q->whereDate('payment_date', '<=', $to))
                ->orderBy('payment_date')->get();
            $rows = [];
            foreach ($invoices as $inv) {
                $rows[] = [optional($inv->invoice_date)?->format('Y-m-d'), 'فاتورة', $inv->invoice_number, $inv->total, 0, $inv->currency];
            }
            foreach ($payments as $pay) {
                $rows[] = [optional($pay->payment_date)?->format('Y-m-d'), 'دفع', $pay->payment_number, 0, $pay->amount, $pay->currency];
            }
            usort($rows, fn ($a, $b) => strcmp((string) $a[0], (string) $b[0]));
            $book->addSheet('كشف مورد '.$supplier->name, ['التاريخ', 'النوع', 'المرجع', 'مدين', 'دائن', 'العملة'], $rows);
        }
    }

    /**
     * Flatten nested report arrays into a readable sheet.
     *
     * @param  array<string, mixed>  $data
     */
    protected function sheetGenericReport(ExcelWorkbook $book, string $title, array $data): void
    {
        $rowsRaw = $data['rows'] ?? null;
        if ($rowsRaw instanceof \Illuminate\Support\Collection) {
            $rowsRaw = $rowsRaw->all();
        }
        if (is_array($rowsRaw) && $rowsRaw !== []) {
            $normalized = [];
            foreach ($rowsRaw as $row) {
                if ($row instanceof \Illuminate\Database\Eloquent\Model) {
                    $normalized[] = $row->toArray();
                } elseif (is_array($row)) {
                    $normalized[] = $row;
                } else {
                    $normalized[] = ['value' => $row];
                }
            }
            $first = $normalized[0];
            $headers = array_map(fn ($k) => (string) $k, array_keys($first));
            $rows = array_map(function ($r) use ($headers) {
                $vals = [];
                foreach ($headers as $h) {
                    $v = $r[$h] ?? '';
                    if (is_array($v) || is_object($v)) {
                        $v = json_encode($v, JSON_UNESCAPED_UNICODE);
                    }
                    $vals[] = $v;
                }

                return $vals;
            }, $normalized);
            $book->addSheet($title, $headers, $rows);

            return;
        }

        $summary = [];
        $nestedSheets = 0;
        foreach ($data as $key => $value) {
            if ($value instanceof \Illuminate\Support\Collection) {
                $value = $value->all();
            }
            if (is_array($value) && $value !== [] && isset($value[0]) && (is_array($value[0]) || $value[0] instanceof \Illuminate\Database\Eloquent\Model)) {
                $mapped = array_map(fn ($r) => $r instanceof \Illuminate\Database\Eloquent\Model ? $r->toArray() : $r, $value);
                $headers = array_map(fn ($k) => (string) $k, array_keys($mapped[0]));
                $rows = array_map(fn ($r) => array_map(fn ($h) => is_array($r[$h] ?? null) || is_object($r[$h] ?? null)
                    ? json_encode($r[$h] ?? null, JSON_UNESCAPED_UNICODE)
                    : ($r[$h] ?? ''), $headers), $mapped);
                $book->addSheet($title.' — '.$key, $headers, $rows);
                $nestedSheets++;
            } elseif (! is_array($value)) {
                $summary[] = [(string) $key, $value];
            } else {
                $summary[] = [(string) $key, json_encode($value, JSON_UNESCAPED_UNICODE)];
            }
        }
        if ($summary !== [] || $nestedSheets === 0) {
            $book->addSheet($title, ['الحقل', 'القيمة'], $summary !== [] ? $summary : [['ملاحظة', 'لا توجد بيانات']]);
        }
    }

    protected function applyDateFilter(Builder $query, string $column, Request $request): void
    {
        if ($request->filled('from')) {
            $query->whereDate($column, '>=', $request->query('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate($column, '<=', $request->query('to'));
        }
    }

    protected function streamDownload(ExcelWorkbook $book, string $filename): StreamedResponse
    {
        $book->ensureNotEmpty();

        return response()->streamDownload(function () use ($book) {
            $tmp = $book->toTempStream();
            fpassthru($tmp);
            fclose($tmp);
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    protected function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $size = (float) $bytes;
        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }

        return round($size, 2).' '.$units[$i];
    }
}
