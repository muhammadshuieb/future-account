<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    public function __construct(
        protected JournalEntryService $journals,
        protected AuditLogger $audit,
    ) {}

    public function adjustStock(
        int $warehouseId,
        int $productId,
        float $quantityDelta,
        string $type,
        User $user,
        array $meta = []
    ): StockMovement {
        return DB::transaction(function () use ($warehouseId, $productId, $quantityDelta, $type, $user, $meta) {
            $batch = $meta['batch_no'] ?? '';
            $level = StockLevel::query()->firstOrCreate(
                [
                    'warehouse_id' => $warehouseId,
                    'product_id' => $productId,
                    'batch_no' => $batch === null ? '' : $batch,
                ],
                ['quantity' => 0]
            );

            $newQty = round((float) $level->quantity + $quantityDelta, 3);

            if ($newQty < -0.0001) {
                throw ValidationException::withMessages([
                    'quantity' => ['الكمية غير كافية في المخزن.'],
                ]);
            }

            $level->update(['quantity' => max(0, $newQty)]);

            $movement = StockMovement::query()->create([
                'movement_number' => $this->nextMovementNumber(),
                'movement_date' => $meta['movement_date'] ?? now()->toDateString(),
                'type' => $type,
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'quantity' => $quantityDelta,
                'unit_cost' => $meta['unit_cost'] ?? Product::query()->find($productId)?->cost_price ?? 0,
                'batch_no' => $meta['batch_no'] ?? null,
                'serial_no' => $meta['serial_no'] ?? null,
                'reference_type' => $meta['reference_type'] ?? null,
                'reference_id' => $meta['reference_id'] ?? null,
                'journal_entry_id' => $meta['journal_entry_id'] ?? null,
                'notes' => $meta['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            $this->audit->log($user, 'stock.'.$type, $movement, null, $movement->toArray());

            return $movement;
        });
    }

    /**
     * Manual stock in/out with optional GL posting (inventory vs adjustment expense/income).
     */
    public function createManualMovement(array $data, User $user): StockMovement
    {
        $qty = (float) $data['quantity'];
        $type = $data['type']; // in | out | adjustment

        if (! in_array($type, ['in', 'out', 'adjustment'], true)) {
            throw ValidationException::withMessages(['type' => ['نوع حركة غير صالح.']]);
        }

        $delta = $type === 'out' ? -abs($qty) : abs($qty);
        if ($type === 'adjustment') {
            $delta = $qty; // signed
        }

        $journalId = null;
        $product = Product::query()->findOrFail($data['product_id']);
        $unitCost = (float) ($data['unit_cost'] ?? $product->cost_price);
        $amount = round(abs($delta) * $unitCost, 2);

        if ($amount > 0 && ! empty($data['post_to_gl'])) {
            $inventory = Account::query()->where('code', '1104')->firstOrFail();
            $offset = Account::query()->where('code', $delta >= 0 ? '4102' : '5104')->firstOrFail();

            $lines = $delta >= 0
                ? [
                    ['account_id' => $inventory->id, 'debit' => $amount, 'credit' => 0],
                    ['account_id' => $offset->id, 'debit' => 0, 'credit' => $amount],
                ]
                : [
                    ['account_id' => $offset->id, 'debit' => $amount, 'credit' => 0],
                    ['account_id' => $inventory->id, 'debit' => 0, 'credit' => $amount],
                ];

            $entry = $this->journals->create([
                'entry_date' => $data['movement_date'] ?? now()->toDateString(),
                'description' => 'حركة مخزون '.$type,
                'reference' => $data['notes'] ?? null,
                'status' => 'posted',
            ], $lines, $user);

            $journalId = $entry->id;
        }

        return $this->adjustStock(
            (int) $data['warehouse_id'],
            (int) $data['product_id'],
            $delta,
            $type,
            $user,
            [
                'movement_date' => $data['movement_date'] ?? now()->toDateString(),
                'unit_cost' => $unitCost,
                'batch_no' => $data['batch_no'] ?? null,
                'serial_no' => $data['serial_no'] ?? null,
                'notes' => $data['notes'] ?? null,
                'journal_entry_id' => $journalId,
            ]
        );
    }

    public function transfer(array $data, array $lines, User $user): \App\Models\WarehouseTransfer
    {
        return DB::transaction(function () use ($data, $lines, $user) {
            $transfer = \App\Models\WarehouseTransfer::query()->create([
                'transfer_number' => $this->nextNumber('TRF'),
                'transfer_date' => $data['transfer_date'],
                'from_warehouse_id' => $data['from_warehouse_id'],
                'to_warehouse_id' => $data['to_warehouse_id'],
                'status' => 'draft',
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            foreach ($lines as $line) {
                $transfer->lines()->create([
                    'product_id' => $line['product_id'],
                    'quantity' => $line['quantity'],
                ]);
            }

            if (($data['status'] ?? 'draft') === 'posted') {
                return $this->postTransfer($transfer, $user);
            }

            return $transfer->load(['lines.product', 'fromWarehouse', 'toWarehouse']);
        });
    }

    public function postTransfer(\App\Models\WarehouseTransfer $transfer, User $user): \App\Models\WarehouseTransfer
    {
        if ($transfer->status === 'posted') {
            throw ValidationException::withMessages(['status' => ['التحويل مرحّل مسبقاً.']]);
        }

        return DB::transaction(function () use ($transfer, $user) {
            $transfer->load('lines');

            foreach ($transfer->lines as $line) {
                $this->adjustStock(
                    $transfer->from_warehouse_id,
                    $line->product_id,
                    -((float) $line->quantity),
                    'transfer',
                    $user,
                    [
                        'movement_date' => $transfer->transfer_date->toDateString(),
                        'reference_type' => $transfer::class,
                        'reference_id' => $transfer->id,
                        'notes' => 'تحويل صادر '.$transfer->transfer_number,
                    ]
                );

                $this->adjustStock(
                    $transfer->to_warehouse_id,
                    $line->product_id,
                    (float) $line->quantity,
                    'transfer',
                    $user,
                    [
                        'movement_date' => $transfer->transfer_date->toDateString(),
                        'reference_type' => $transfer::class,
                        'reference_id' => $transfer->id,
                        'notes' => 'تحويل وارد '.$transfer->transfer_number,
                    ]
                );
            }

            $transfer->update(['status' => 'posted']);

            return $transfer->fresh(['lines.product', 'fromWarehouse', 'toWarehouse']);
        });
    }

    public function postInventoryCount(\App\Models\InventoryCount $count, User $user): \App\Models\InventoryCount
    {
        if ($count->status === 'posted') {
            throw ValidationException::withMessages(['status' => ['الجرد مرحّل مسبقاً.']]);
        }

        return DB::transaction(function () use ($count, $user) {
            $count->load('lines');

            foreach ($count->lines as $line) {
                $diff = (float) $line->difference;
                if (abs($diff) < 0.0001) {
                    continue;
                }

                $this->adjustStock(
                    $count->warehouse_id,
                    $line->product_id,
                    $diff,
                    'count',
                    $user,
                    [
                        'movement_date' => $count->count_date->toDateString(),
                        'reference_type' => $count::class,
                        'reference_id' => $count->id,
                        'notes' => 'تسوية جرد '.$count->count_number,
                        'post_to_gl' => false,
                    ]
                );
            }

            $count->update(['status' => 'posted']);

            return $count->fresh(['lines.product', 'warehouse']);
        });
    }

    public function lowStockAlerts(): array
    {
        return Product::query()
            ->where('is_active', true)
            ->withSum('stockLevels as on_hand', 'quantity')
            ->get()
            ->filter(fn (Product $p) => (float) ($p->on_hand ?? 0) <= (float) $p->reorder_level)
            ->map(fn (Product $p) => [
                'id' => $p->id,
                'sku' => $p->sku,
                'name' => $p->name,
                'on_hand' => (float) ($p->on_hand ?? 0),
                'reorder_level' => (float) $p->reorder_level,
            ])
            ->values()
            ->all();
    }

    protected function nextMovementNumber(): string
    {
        return $this->nextNumber('SM');
    }

    public function nextNumber(string $prefix): string
    {
        $year = now()->format('Y');
        $full = "{$prefix}-{$year}-";

        $last = match ($prefix) {
            'SM' => StockMovement::query()->where('movement_number', 'like', $full.'%')->orderByDesc('movement_number')->value('movement_number'),
            'TRF' => \App\Models\WarehouseTransfer::query()->where('transfer_number', 'like', $full.'%')->orderByDesc('transfer_number')->value('transfer_number'),
            'CNT' => \App\Models\InventoryCount::query()->where('count_number', 'like', $full.'%')->orderByDesc('count_number')->value('count_number'),
            default => null,
        };

        $seq = $last ? ((int) substr($last, strlen($full))) + 1 : 1;

        return $full.str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
    }
}
