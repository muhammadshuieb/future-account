<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BackupController;
use App\Http\Controllers\Api\BankController;
use App\Http\Controllers\Api\BankReconciliationController;
use App\Http\Controllers\Api\BarcodeController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\CashBoxController;
use App\Http\Controllers\Api\CashTransferController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\CurrencyController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\JournalEntryController;
use App\Http\Controllers\Api\LeaveRequestController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\PurchaseOrderController;
use App\Http\Controllers\Api\PurchaseRequestController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\PurchaseInvoiceController;
use App\Http\Controllers\Api\PurchaseReturnController;
use App\Http\Controllers\Api\SalesOrderController;
use App\Http\Controllers\Api\SalesQuoteController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\ReceiptController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SalaryRecordController;
use App\Http\Controllers\Api\SalesInvoiceController;
use App\Http\Controllers\Api\SalesReturnController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\SupplierPaymentController;
use App\Http\Controllers\Api\UnitController;
use App\Http\Controllers\Api\WarehouseController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('register', [AuthController::class, 'register']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::post('auth/logout', [AuthController::class, 'logout']);

    Route::get('dashboard/summary', [DashboardController::class, 'summary']);

    Route::apiResource('accounts', AccountController::class);
    Route::apiResource('journal-entries', JournalEntryController::class);
    Route::post('journal-entries/{journalEntry}/post', [JournalEntryController::class, 'post']);
    Route::post('journal-entries/{journalEntry}/void', [JournalEntryController::class, 'void']);

    Route::get('settings', [SettingController::class, 'index']);
    Route::put('settings', [SettingController::class, 'update']);

    // Currencies
    Route::get('currencies', [CurrencyController::class, 'index']);
    Route::get('exchange-rates', [CurrencyController::class, 'rates']);
    Route::post('exchange-rates', [CurrencyController::class, 'storeRate']);
    Route::post('currencies/convert', [CurrencyController::class, 'convert']);

    // Backups (admin)
    Route::get('backups/status', [BackupController::class, 'status']);
    Route::get('backups', [BackupController::class, 'index']);
    Route::post('backups', [BackupController::class, 'store']);
    Route::get('backups/{filename}/download', [BackupController::class, 'download'])->where('filename', '.*');
    Route::post('backups/restore', [BackupController::class, 'restore']);
    Route::delete('backups/{filename}', [BackupController::class, 'destroy'])->where('filename', '.*');

    // Barcodes / labels
    Route::get('barcodes/labels', [BarcodeController::class, 'productLabels']);
    Route::post('products/{product}/barcode', [BarcodeController::class, 'generateForProduct']);
    Route::get('sales-invoices/{salesInvoice}/qr', [BarcodeController::class, 'invoiceQr']);
    Route::get('sales-invoices/{salesInvoice}/e-invoice', [BarcodeController::class, 'eInvoice']);

    // Notifications stub
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead']);
    Route::post('notifications/read-all', [NotificationController::class, 'markAllRead']);

    // Phase 2 — Inventory
    Route::apiResource('warehouses', WarehouseController::class);
    Route::apiResource('categories', CategoryController::class)->except(['show']);
    Route::apiResource('units', UnitController::class)->except(['show']);
    Route::apiResource('products', ProductController::class);
    Route::get('products/{product}/stock', [ProductController::class, 'stock']);

    Route::get('stock-levels', [InventoryController::class, 'stockLevels']);
    Route::get('stock-movements', [InventoryController::class, 'movements']);
    Route::post('stock-movements', [InventoryController::class, 'storeMovement']);
    Route::get('stock-alerts', [InventoryController::class, 'alerts']);
    Route::get('warehouse-transfers', [InventoryController::class, 'transfers']);
    Route::post('warehouse-transfers', [InventoryController::class, 'storeTransfer']);
    Route::post('warehouse-transfers/{warehouseTransfer}/post', [InventoryController::class, 'postTransfer']);
    Route::get('inventory-counts', [InventoryController::class, 'counts']);
    Route::post('inventory-counts', [InventoryController::class, 'storeCount']);
    Route::post('inventory-counts/{inventoryCount}/post', [InventoryController::class, 'postCount']);

    // Phase 3 — Sales & Purchases
    Route::apiResource('customers', CustomerController::class);
    Route::get('customers/{customer}/statement', [CustomerController::class, 'statement']);
    Route::apiResource('suppliers', SupplierController::class);
    Route::get('suppliers/{supplier}/statement', [SupplierController::class, 'statement']);

    Route::get('sales-quotes', [SalesQuoteController::class, 'index']);
    Route::post('sales-quotes', [SalesQuoteController::class, 'store']);
    Route::get('sales-quotes/{salesQuote}', [SalesQuoteController::class, 'show']);
    Route::put('sales-quotes/{salesQuote}', [SalesQuoteController::class, 'update']);
    Route::delete('sales-quotes/{salesQuote}', [SalesQuoteController::class, 'destroy']);
    Route::post('sales-quotes/{salesQuote}/convert-to-order', [SalesQuoteController::class, 'convertToOrder']);

    Route::get('sales-orders', [SalesOrderController::class, 'index']);
    Route::post('sales-orders', [SalesOrderController::class, 'store']);
    Route::get('sales-orders/{salesOrder}', [SalesOrderController::class, 'show']);
    Route::delete('sales-orders/{salesOrder}', [SalesOrderController::class, 'destroy']);
    Route::post('sales-orders/{salesOrder}/convert-to-invoice', [SalesOrderController::class, 'convertToInvoice']);

    Route::get('sales-invoices', [SalesInvoiceController::class, 'index']);
    Route::post('sales-invoices', [SalesInvoiceController::class, 'store']);
    Route::get('sales-invoices/{salesInvoice}', [SalesInvoiceController::class, 'show']);
    Route::post('sales-invoices/{salesInvoice}/post', [SalesInvoiceController::class, 'post']);
    Route::delete('sales-invoices/{salesInvoice}', [SalesInvoiceController::class, 'destroy']);

    Route::get('sales-returns', [SalesReturnController::class, 'index']);
    Route::post('sales-returns', [SalesReturnController::class, 'store']);
    Route::post('sales-returns/{salesReturn}/post', [SalesReturnController::class, 'post']);
    Route::delete('sales-returns/{salesReturn}', [SalesReturnController::class, 'destroy']);

    Route::get('receipts', [ReceiptController::class, 'index']);
    Route::post('receipts', [ReceiptController::class, 'store']);
    Route::post('receipts/{receipt}/post', [ReceiptController::class, 'post']);
    Route::delete('receipts/{receipt}', [ReceiptController::class, 'destroy']);

    Route::get('purchase-requests', [PurchaseRequestController::class, 'index']);
    Route::post('purchase-requests', [PurchaseRequestController::class, 'store']);
    Route::get('purchase-requests/{purchaseRequest}', [PurchaseRequestController::class, 'show']);
    Route::put('purchase-requests/{purchaseRequest}', [PurchaseRequestController::class, 'update']);
    Route::delete('purchase-requests/{purchaseRequest}', [PurchaseRequestController::class, 'destroy']);
    Route::post('purchase-requests/{purchaseRequest}/convert-to-order', [PurchaseRequestController::class, 'convertToOrder']);

    Route::get('purchase-orders', [PurchaseOrderController::class, 'index']);
    Route::post('purchase-orders', [PurchaseOrderController::class, 'store']);
    Route::get('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show']);
    Route::delete('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'destroy']);
    Route::post('purchase-orders/{purchaseOrder}/convert-to-invoice', [PurchaseOrderController::class, 'convertToInvoice']);

    Route::get('purchase-invoices', [PurchaseInvoiceController::class, 'index']);
    Route::post('purchase-invoices', [PurchaseInvoiceController::class, 'store']);
    Route::get('purchase-invoices/{purchaseInvoice}', [PurchaseInvoiceController::class, 'show']);
    Route::post('purchase-invoices/{purchaseInvoice}/post', [PurchaseInvoiceController::class, 'post']);
    Route::delete('purchase-invoices/{purchaseInvoice}', [PurchaseInvoiceController::class, 'destroy']);

    Route::get('purchase-returns', [PurchaseReturnController::class, 'index']);
    Route::post('purchase-returns', [PurchaseReturnController::class, 'store']);
    Route::post('purchase-returns/{purchaseReturn}/post', [PurchaseReturnController::class, 'post']);
    Route::delete('purchase-returns/{purchaseReturn}', [PurchaseReturnController::class, 'destroy']);

    Route::get('supplier-payments', [SupplierPaymentController::class, 'index']);
    Route::post('supplier-payments', [SupplierPaymentController::class, 'store']);
    Route::post('supplier-payments/{supplierPayment}/post', [SupplierPaymentController::class, 'post']);
    Route::delete('supplier-payments/{supplierPayment}', [SupplierPaymentController::class, 'destroy']);

    // Phase 4 — Cash & Reports
    Route::apiResource('cash-boxes', CashBoxController::class)->except(['show']);
    Route::apiResource('banks', BankController::class)->except(['show']);
    Route::get('banks/{bank}/book-balance', [BankController::class, 'bookBalance']);
    Route::get('cash-transfers', [CashTransferController::class, 'index']);
    Route::post('cash-transfers', [CashTransferController::class, 'store']);
    Route::post('cash-transfers/{cashTransfer}/post', [CashTransferController::class, 'post']);
    Route::get('bank-reconciliations', [BankReconciliationController::class, 'index']);
    Route::post('bank-reconciliations', [BankReconciliationController::class, 'store']);

    Route::prefix('reports')->group(function () {
        Route::get('trial-balance', [ReportController::class, 'trialBalance']);
        Route::get('income-statement', [ReportController::class, 'incomeStatement']);
        Route::get('balance-sheet', [ReportController::class, 'balanceSheet']);
        Route::get('cash-flow', [ReportController::class, 'cashFlow']);
        Route::get('sales', [ReportController::class, 'sales']);
        Route::get('purchases', [ReportController::class, 'purchases']);
        Route::get('inventory', [ReportController::class, 'inventory']);
        Route::get('profit', [ReportController::class, 'profit']);
        Route::get('tax', [ReportController::class, 'tax']);
        Route::get('general-ledger', [ReportController::class, 'generalLedger']);
        Route::get('product-movement/{product}', [ReportController::class, 'productMovement']);
    });

    // Phase 5 — HR
    Route::apiResource('employees', EmployeeController::class)->except(['show']);
    Route::get('attendances', [AttendanceController::class, 'index']);
    Route::post('attendances', [AttendanceController::class, 'store']);
    Route::delete('attendances/{attendance}', [AttendanceController::class, 'destroy']);
    Route::apiResource('leave-requests', LeaveRequestController::class)->except(['show']);
    Route::get('salary-records', [SalaryRecordController::class, 'index']);
    Route::post('salary-records', [SalaryRecordController::class, 'store']);
    Route::post('salary-records/{salaryRecord}/post', [SalaryRecordController::class, 'post']);

    // Phase 6 — Multi-branch / audit
    Route::apiResource('companies', CompanyController::class)->except(['show', 'destroy']);
    Route::apiResource('branches', BranchController::class)->except(['show', 'destroy']);
    Route::get('audit-logs', [AuditLogController::class, 'index']);

    Route::get('users', [UserController::class, 'index']);
    Route::post('users', [UserController::class, 'store']);
    Route::put('users/{user}', [UserController::class, 'update']);
    Route::delete('users/{user}', [UserController::class, 'destroy']);
    Route::get('roles', [RoleController::class, 'index']);
    Route::put('roles/{role}', [RoleController::class, 'update']);
});
