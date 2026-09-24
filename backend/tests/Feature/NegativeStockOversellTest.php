<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\JournalDetail;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\Setting;
use App\Models\StockLevel;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\ErpDemoSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NegativeStockOversellTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, ChartOfAccountsSeeder::class, ErpDemoSeeder::class]);
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('admin');
        Sanctum::actingAs($user);
    }

    public function test_sales_still_blocked_when_allow_negative_stock_is_off(): void
    {
        Setting::setValue('allow_negative_stock', '0', 'warehouse', 'boolean', 'allow negative');

        $customer = Customer::query()->where('code', 'CUS-001')->firstOrFail();
        $warehouse = Warehouse::query()->where('code', 'WH-01')->firstOrFail();
        $product = Product::query()->where('sku', 'PRD-002')->firstOrFail();
        $product->update(['cost_price' => 0]);

        $this->postJson('/api/sales-invoices', [
            'invoice_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'posted',
            'payment_type' => 'credit',
            'lines' => [['product_id' => $product->id, 'quantity' => 3, 'unit_price' => 100, 'tax_rate' => 0]],
        ])->assertStatus(422);
    }

    public function test_sell_with_zero_stock_goes_negative_then_purchase_offsets(): void
    {
        Setting::setValue('allow_negative_stock', '1', 'warehouse', 'boolean', 'allow negative');

        $customer = Customer::query()->where('code', 'CUS-001')->firstOrFail();
        $warehouse = Warehouse::query()->where('code', 'WH-01')->firstOrFail();
        $supplier = Supplier::query()->where('code', 'SUP-001')->firstOrFail();
        $product = Product::query()->where('sku', 'PRD-002')->firstOrFail();
        $product->update(['cost_price' => 0, 'track_batch' => false]);

        // Ensure no leftover stock for this product.
        StockLevel::query()->where('product_id', $product->id)->delete();

        $sales = $this->postJson('/api/sales-invoices', [
            'invoice_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'posted',
            'payment_type' => 'credit',
            'lines' => [['product_id' => $product->id, 'quantity' => 5, 'unit_price' => 140, 'tax_rate' => 0]],
        ]);

        $sales->assertCreated()->assertJsonPath('data.status', 'posted');

        $onHand = (float) StockLevel::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->sum('quantity');
        $this->assertSame(-5.0, $onHand);

        $invoiceId = $sales->json('data.id');
        $journalId = $sales->json('data.journal_entry_id');
        $this->assertNotNull($journalId);

        // Unknown cost → no COGS lines; AR/Sales journal must still balance.
        $this->assertJournalBalanced((int) $journalId);
        $cogsPosted = JournalDetail::query()
            ->where('journal_entry_id', $journalId)
            ->whereHas('account', fn ($q) => $q->where('code', '5101'))
            ->exists();
        $this->assertFalse($cogsPosted, 'COGS should be omitted when product cost is zero');

        // Purchase arrives and offsets the negative balance.
        $this->postJson('/api/purchase-invoices', [
            'invoice_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'posted',
            'payment_type' => 'credit',
            'lines' => [['product_id' => $product->id, 'quantity' => 8, 'unit_cost' => 80, 'tax_rate' => 0]],
        ])->assertCreated()->assertJsonPath('data.status', 'posted');

        $after = (float) StockLevel::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->sum('quantity');
        $this->assertSame(3.0, $after);

        $product->refresh();
        $this->assertEquals(80.0, (float) $product->cost_price);

        $this->getJson("/api/products/{$product->id}/stock?warehouse_id={$warehouse->id}")
            ->assertOk()
            ->assertJsonPath('data.available_qty', 3)
            ->assertJsonPath('data.on_hand_qty', 3)
            ->assertJsonPath('data.allow_negative_stock', true);

        // Sales journal still present and balanced after purchase.
        $this->assertJournalBalanced((int) $journalId);
        $this->assertDatabaseHas('sales_invoices', ['id' => $invoiceId, 'status' => 'posted']);
    }

    public function test_transfer_still_blocked_even_when_allow_negative_stock_is_on(): void
    {
        Setting::setValue('allow_negative_stock', '1', 'warehouse', 'boolean', 'allow negative');

        $from = Warehouse::query()->where('code', 'WH-01')->firstOrFail();
        $to = Warehouse::query()->where('code', 'WH-02')->firstOrFail();
        $product = Product::query()->where('sku', 'PRD-003')->firstOrFail();
        StockLevel::query()->where('product_id', $product->id)->where('warehouse_id', $from->id)->delete();

        $this->postJson('/api/warehouse-transfers', [
            'transfer_date' => now()->toDateString(),
            'from_warehouse_id' => $from->id,
            'to_warehouse_id' => $to->id,
            'status' => 'posted',
            'lines' => [['product_id' => $product->id, 'quantity' => 2]],
        ])->assertStatus(422);
    }

    protected function assertJournalBalanced(int $journalEntryId): void
    {
        $entry = JournalEntry::query()->with('details')->findOrFail($journalEntryId);
        $debit = round((float) $entry->details->sum('debit'), 2);
        $credit = round((float) $entry->details->sum('credit'), 2);
        $this->assertSame($debit, $credit, "Journal #{$journalEntryId} unbalanced: Dr {$debit} Cr {$credit}");
    }
}
