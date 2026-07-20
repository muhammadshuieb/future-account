<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Auditable models
    |--------------------------------------------------------------------------
    |
    | Eloquent models that automatically write audit_logs on create/update/delete.
    | Line items, stock levels, and notifications are excluded to avoid noise.
    |
    */
    'models' => [
        App\Models\Account::class,
        App\Models\Attendance::class,
        App\Models\Bank::class,
        App\Models\BankReconciliation::class,
        App\Models\Branch::class,
        App\Models\CashBox::class,
        App\Models\CashTransfer::class,
        App\Models\Category::class,
        App\Models\Company::class,
        App\Models\Currency::class,
        App\Models\Customer::class,
        App\Models\Employee::class,
        App\Models\ExchangeRate::class,
        App\Models\InventoryCount::class,
        App\Models\JournalEntry::class,
        App\Models\LeaveRequest::class,
        App\Models\Product::class,
        App\Models\PurchaseInvoice::class,
        App\Models\PurchaseOrder::class,
        App\Models\PurchaseRequest::class,
        App\Models\PurchaseReturn::class,
        App\Models\Receipt::class,
        App\Models\SalaryRecord::class,
        App\Models\SalesInvoice::class,
        App\Models\SalesOrder::class,
        App\Models\SalesQuote::class,
        App\Models\SalesReturn::class,
        App\Models\Setting::class,
        App\Models\Supplier::class,
        App\Models\SupplierPayment::class,
        App\Models\Unit::class,
        App\Models\User::class,
        App\Models\Warehouse::class,
        App\Models\WarehouseTransfer::class,
    ],

    'hidden_attributes' => [
        'password',
        'remember_token',
        'api_token',
    ],
];
