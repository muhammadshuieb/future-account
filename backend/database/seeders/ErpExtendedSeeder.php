<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Product;
use App\Models\PurchaseRequest;
use App\Models\SalesQuote;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\PurchaseService;
use App\Services\SalesService;
use Illuminate\Database\Seeder;

class ErpExtendedSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()->first();
        if (! $user) {
            return;
        }

        $customer = Customer::query()->where('code', 'CUS-001')->first();
        $supplier = Supplier::query()->where('code', 'SUP-001')->first();
        $warehouse = Warehouse::query()->where('code', 'WH-01')->first();
        $product = Product::query()->where('sku', 'PRD-001')->first();
        $product2 = Product::query()->where('sku', 'PRD-002')->first();

        if ($product) {
            $product->update(['track_batch' => true]);
        }
        if ($product2) {
            $product2->update(['track_serial' => true]);
        }

        if ($customer && $warehouse && $product && ! SalesQuote::query()->exists()) {
            app(SalesService::class)->createQuote([
                'quote_date' => now()->toDateString(),
                'valid_until' => now()->addDays(14)->toDateString(),
                'customer_id' => $customer->id,
                'warehouse_id' => $warehouse->id,
                'status' => 'confirmed',
            ], [
                ['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 1200, 'tax_rate' => 15, 'batch_no' => 'BATCH-DEMO-001'],
            ], $user);
        }

        if ($supplier && $warehouse && $product && ! PurchaseRequest::query()->exists()) {
            app(PurchaseService::class)->createRequest([
                'request_date' => now()->toDateString(),
                'required_date' => now()->addDays(7)->toDateString(),
                'supplier_id' => $supplier->id,
                'warehouse_id' => $warehouse->id,
                'status' => 'approved',
            ], [
                ['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 800, 'tax_rate' => 15, 'batch_no' => 'BATCH-DEMO-002'],
            ], $user);
        }
    }
}
