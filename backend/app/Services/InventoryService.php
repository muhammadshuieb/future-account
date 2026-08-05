<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Product;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    public function __construct(
        protected JournalEntryService $journals,
        protected AuditLogger $audit,
    ) {}

    public function availableQty(int $warehouseId, int $productId, ?string $batchNo = null, ?Product $product = null): float
    {
        $product ??= Product::query()->findOrFail($productId);

        $query = StockLevel::query()
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->where('quantity', '>', 0);

        if ($product->track_batch && $batchNo !== null && $batchNo !== '') {
            $query->where('batch_no', $batchNo);
        }

        return round(max(0, (float) $query->sum('quantity')), 3);
    }

    /**
     * Positive stock rows for a product, optionally scoped to one warehouse.
     *
     * @return array<int, array{warehouse_id: int, warehouse_name: string, batch_no: string, quantity: float}>
     */
    public function stockBreakdown(int $productId, ?int $warehouseId = null): array
    {
        $query = StockLevel::query()
            ->with('warehouse:id,name')
            ->where('product_id', $productId)
            ->where('quantity', '>', 0)
            ->orderBy('warehouse_id')
            ->orderBy('batch_no');

        if ($warehouseId !== null) {
            $query->where('warehouse_id', $warehouseId);
        }

        return $query->get()->map(fn (StockLevel $level) => [
            'warehouse_id' => (int) $level->warehouse_id,
            'warehouse_name' => $level->warehouse?->name ?? '—',
            'batch_no' => (string) ($level->batch_no ?? ''),
            'quantity' => round((float) $level->quantity, 3),
        ])->all();
    }

    /**
     * New carrying cost per unit after receiving `$incomingQty` at `$incomingUnitCost`.
     *
     * Stock is valued at a moving weighted average, which keeps `on_hand × cost_price`
     * in step with what was capitalised into the inventory control account.
     */
    public function movingAverageCost(Product $product, float $incomingQty, float $incomingUnitCost): float
    {
        if ($incomingQty <= 0) {
            return round((float) $product->cost_price, 2);
        }

        $existingQty = round((float) StockLevel::query()
            ->where('product_id', $product->id)
            ->where('quantity', '>', 0)
            ->sum('quantity'), 3);

        if ($existingQty <= 0) {
            return round($incomingUnitCost, 2);
        }

        $existingValue = $existingQty * (float) $product->cost_price;
        $incomingValue = $incomingQty * $incomingUnitCost;

        return round(($existingValue + $incomingValue) / ($existingQty + $incomingQty), 2);
    }

    public function adjustStock(
        int $warehouseId,
        int $productId,
        float $quantityDelta,
        string $type,
        User $user,
        array $meta = []
    ): StockMovement {
        return DB::transaction(function () use ($warehouseId, $productId, $quantityDelta, $type, $user, $meta) {
            $product = Product::query()->findOrFail($productId);

            if ($quantityDelta < 0) {
                return $this->deductStock($warehouseId, $product, abs($quantityDelta), $type, $user, $meta);
            }

            $this->validateBatchSerial($product, $meta);
            $batch = $this->resolveBatchKey($product, $meta['batch_no'] ?? null);

            return $this->adjustSingleBatchLevel(
                $warehouseId,
                $product,
                $batch,
                $quantityDelta,
                $type,
                $user,
                $meta
            );
        });
    }

    /**
     * Resolve the batch to use for an outbound line (FIFO when preferred batch is empty or insufficient).
     */
    public function resolveOutboundBatch(
        int $warehouseId,
        Product $product,
        float $requiredQty,
        ?string $preferredBatch = null
    ): ?string {
        if (! $product->track_batch) {
            return $preferredBatch;
        }

        if ($preferredBatch !== null && $preferredBatch !== ''
            && $this->availableQty($warehouseId, $product->id, $preferredBatch, $product) >= $requiredQty - 0.0001) {
            return $preferredBatch;
        }

        $fifoBatch = $this->pickFifoBatch($warehouseId, $product->id, $requiredQty);
        if ($fifoBatch !== null) {
            return $fifoBatch;
        }

        $totalAvailable = $this->availableQty($warehouseId, $product->id, null, $product);
        if ($totalAvailable >= $requiredQty - 0.0001) {
            return $this->firstFifoBatch($warehouseId, $product->id);
        }

        return $preferredBatch;
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
                'branch_id' => Warehouse::query()->whereKey((int) $data['warehouse_id'])->value('branch_id'),
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

    /**
     * Validate batch/serial requirements for products with tracking flags.
     */
    public function validateBatchSerial(Product $product, array $line, bool $forOutbound = false): void
    {
        if ($product->track_batch && empty($line['batch_no']) && ! $forOutbound) {
            throw ValidationException::withMessages([
                'batch_no' => ["الصنف {$product->name} يتطلب رقم دفعة."],
            ]);
        }

        if ($product->track_serial && empty($line['serial_no'])) {
            throw ValidationException::withMessages([
                'serial_no' => ["الصنف {$product->name} يتطلب رقم تسلسلي."],
            ]);
        }
    }

    public function assertSufficientStock(
        int $warehouseId,
        int $productId,
        float $requiredQty,
        ?string $batchNo = null,
        ?Product $product = null
    ): void {
        $product ??= Product::query()->findOrFail($productId);

        if ($product->track_batch && $batchNo !== null && $batchNo !== '') {
            $batchAvailable = $this->availableQty($warehouseId, $productId, $batchNo, $product);
            if ($requiredQty <= $batchAvailable + 0.0001) {
                return;
            }
        }

        $totalAvailable = $this->availableQty($warehouseId, $productId, null, $product);

        if ($requiredQty > $totalAvailable + 0.0001) {
            $batchAvailable = ($product->track_batch && $batchNo !== null && $batchNo !== '')
                ? $this->availableQty($warehouseId, $productId, $batchNo, $product)
                : $totalAvailable;

            throw $this->insufficientStockException($product, $warehouseId, $requiredQty, $batchAvailable, $batchNo);
        }
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
                    'batch_no' => $line['batch_no'] ?? null,
                    'serial_no' => $line['serial_no'] ?? null,
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
                $product = \App\Models\Product::query()->findOrFail($line->product_id);
                $qty = (float) $line->quantity;
                $this->validateBatchSerial($product, [
                    'batch_no' => $line->batch_no,
                    'serial_no' => $line->serial_no,
                ], true);

                $batchNo = $line->batch_no;
                if ($product->track_batch) {
                    $batchNo = $this->resolveOutboundBatch(
                        $transfer->from_warehouse_id,
                        $product,
                        $qty,
                        $line->batch_no
                    );
                    if ($batchNo === null || $batchNo === '') {
                        throw ValidationException::withMessages([
                            'batch_no' => ["الصنف {$product->name} يتطلب رقم دفعة."],
                        ]);
                    }
                    $this->assertSufficientStock(
                        $transfer->from_warehouse_id,
                        $line->product_id,
                        $qty,
                        $batchNo,
                        $product
                    );
                    if ($batchNo !== $line->batch_no) {
                        $line->update(['batch_no' => $batchNo]);
                    }
                }

                $meta = [
                    'movement_date' => $transfer->transfer_date->toDateString(),
                    'reference_type' => $transfer::class,
                    'reference_id' => $transfer->id,
                    'batch_no' => $batchNo,
                    'serial_no' => $line->serial_no,
                ];

                $this->adjustStock(
                    $transfer->from_warehouse_id,
                    $line->product_id,
                    -$qty,
                    'transfer',
                    $user,
                    array_merge($meta, ['notes' => 'تحويل صادر '.$transfer->transfer_number])
                );

                $this->adjustStock(
                    $transfer->to_warehouse_id,
                    $line->product_id,
                    $qty,
                    'transfer',
                    $user,
                    array_merge($meta, ['notes' => 'تحويل وارد '.$transfer->transfer_number])
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
            $count->load('lines.product');

            $surplusValue = 0.0;
            $shortageValue = 0.0;

            foreach ($count->lines as $line) {
                $diff = (float) $line->difference;
                if (abs($diff) < 0.0001) {
                    continue;
                }

                $unitCost = (float) ($line->product?->cost_price ?? 0);
                $value = round(abs($diff) * $unitCost, 2);
                if ($diff > 0) {
                    $surplusValue += $value;
                } else {
                    $shortageValue += $value;
                }
            }

            // A physical count changes inventory on hand, so the inventory account must move with it.
            $entryId = $this->postCountAdjustmentEntry(
                $count,
                round($surplusValue, 2),
                round($shortageValue, 2),
                $user
            );

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
                        'unit_cost' => (float) ($line->product?->cost_price ?? 0),
                        'reference_type' => $count::class,
                        'reference_id' => $count->id,
                        'notes' => 'تسوية جرد '.$count->count_number,
                        'journal_entry_id' => $entryId,
                        'batch_no' => $line->batch_no,
                        'serial_no' => $line->serial_no,
                    ]
                );
            }

            $count->update(['status' => 'posted']);

            return $count->fresh(['lines.product', 'warehouse']);
        });
    }

    /**
     * Low-stock alerts scoped per warehouse (never mixes balances across warehouses).
     *
     * @return array<int, array{
     *   id: int,
     *   product_id: int,
     *   sku: string,
     *   name: string,
     *   warehouse_id: int|null,
     *   warehouse_name: string|null,
     *   warehouse_code: string|null,
     *   on_hand: float,
     *   reorder_level: float
     * }>
     */
    public function lowStockAlerts(?int $warehouseId = null): array
    {
        $levels = StockLevel::query()
            ->select('warehouse_id', 'product_id')
            ->selectRaw('SUM(quantity) as on_hand')
            ->when($warehouseId !== null, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->groupBy('warehouse_id', 'product_id')
            ->get()
            ->keyBy(fn (StockLevel $row) => $row->warehouse_id.'-'.$row->product_id);

        $products = Product::query()
            ->where('is_active', true)
            ->where('reorder_level', '>', 0)
            ->get()
            ->keyBy('id');

        $warehouses = Warehouse::query()
            ->when($warehouseId !== null, fn ($q) => $q->where('id', $warehouseId))
            ->get()
            ->keyBy('id');

        $alerts = [];

        foreach ($levels as $row) {
            $product = $products->get($row->product_id);
            if (! $product) {
                continue;
            }

            $onHand = round((float) $row->on_hand, 3);
            $reorder = (float) $product->reorder_level;
            if ($onHand > $reorder) {
                continue;
            }

            $warehouse = $warehouses->get($row->warehouse_id);
            $alerts[] = [
                'id' => (int) $product->id,
                'product_id' => (int) $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'warehouse_id' => (int) $row->warehouse_id,
                'warehouse_name' => $warehouse?->name,
                'warehouse_code' => $warehouse?->code,
                'on_hand' => $onHand,
                'reorder_level' => $reorder,
            ];
        }

        // Products with reorder > 0 but no stock row in the selected warehouse (or anywhere).
        if ($warehouseId !== null) {
            $presentProductIds = $levels->pluck('product_id')->map(fn ($id) => (int) $id)->all();
            $warehouse = $warehouses->get($warehouseId);
            foreach ($products as $product) {
                if (in_array((int) $product->id, $presentProductIds, true)) {
                    continue;
                }
                $alerts[] = [
                    'id' => (int) $product->id,
                    'product_id' => (int) $product->id,
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'warehouse_id' => $warehouseId,
                    'warehouse_name' => $warehouse?->name,
                    'warehouse_code' => $warehouse?->code,
                    'on_hand' => 0.0,
                    'reorder_level' => (float) $product->reorder_level,
                ];
            }
        } else {
            $presentProductIds = $levels->pluck('product_id')->unique()->map(fn ($id) => (int) $id)->all();
            foreach ($products as $product) {
                if (in_array((int) $product->id, $presentProductIds, true)) {
                    continue;
                }
                $alerts[] = [
                    'id' => (int) $product->id,
                    'product_id' => (int) $product->id,
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'warehouse_id' => null,
                    'warehouse_name' => null,
                    'warehouse_code' => null,
                    'on_hand' => 0.0,
                    'reorder_level' => (float) $product->reorder_level,
                ];
            }
        }

        usort($alerts, function (array $a, array $b) {
            $byWarehouse = strcmp((string) ($a['warehouse_name'] ?? ''), (string) ($b['warehouse_name'] ?? ''));
            if ($byWarehouse !== 0) {
                return $byWarehouse;
            }

            return strcmp($a['sku'], $b['sku']);
        });

        return array_values($alerts);
    }

    protected function postCountAdjustmentEntry(
        \App\Models\InventoryCount $count,
        float $surplusValue,
        float $shortageValue,
        User $user
    ): ?int {
        if ($surplusValue <= 0 && $shortageValue <= 0) {
            return null;
        }

        $inventory = Account::query()->where('code', '1104')->firstOrFail();
        $lines = [];

        if ($surplusValue > 0) {
            $otherIncome = Account::query()->where('code', '4102')->firstOrFail();
            $lines[] = ['account_id' => $inventory->id, 'debit' => $surplusValue, 'credit' => 0, 'memo' => 'زيادة جرد'];
            $lines[] = ['account_id' => $otherIncome->id, 'debit' => 0, 'credit' => $surplusValue, 'memo' => 'زيادة جرد'];
        }

        if ($shortageValue > 0) {
            $generalExpense = Account::query()->where('code', '5104')->firstOrFail();
            $lines[] = ['account_id' => $generalExpense->id, 'debit' => $shortageValue, 'credit' => 0, 'memo' => 'نقص جرد'];
            $lines[] = ['account_id' => $inventory->id, 'debit' => 0, 'credit' => $shortageValue, 'memo' => 'نقص جرد'];
        }

        $entry = $this->journals->create([
            'entry_date' => $count->count_date->toDateString(),
            'branch_id' => Warehouse::query()->whereKey($count->warehouse_id)->value('branch_id'),
            'description' => 'تسوية جرد '.$count->count_number,
            'reference' => $count->count_number,
            'status' => 'posted',
        ], $lines, $user);

        return $entry->id;
    }

    protected function resolveBatchKey(Product $product, ?string $batchNo): string
    {
        if (! $product->track_batch) {
            return '';
        }

        return $batchNo === null ? '' : $batchNo;
    }

    protected function deductStock(
        int $warehouseId,
        Product $product,
        float $requiredQty,
        string $type,
        User $user,
        array $meta
    ): StockMovement {
        $preferredBatch = $this->resolveBatchKey($product, $meta['batch_no'] ?? null);

        if (! $product->track_batch) {
            if ($preferredBatch === '') {
                return $this->deductAcrossBatches($warehouseId, $product, $requiredQty, $type, $user, $meta);
            }

            return $this->adjustSingleBatchLevel(
                $warehouseId,
                $product,
                $preferredBatch,
                -$requiredQty,
                $type,
                $user,
                $meta
            );
        }

        if ($preferredBatch !== ''
            && $this->availableQty($warehouseId, $product->id, $preferredBatch, $product) >= $requiredQty - 0.0001) {
            return $this->adjustSingleBatchLevel(
                $warehouseId,
                $product,
                $preferredBatch,
                -$requiredQty,
                $type,
                $user,
                $meta
            );
        }

        $fifoBatch = $this->pickFifoBatch($warehouseId, $product->id, $requiredQty);
        if ($fifoBatch !== null) {
            $meta['batch_no'] = $fifoBatch;

            return $this->adjustSingleBatchLevel(
                $warehouseId,
                $product,
                $fifoBatch,
                -$requiredQty,
                $type,
                $user,
                $meta
            );
        }

        return $this->deductAcrossBatches($warehouseId, $product, $requiredQty, $type, $user, $meta);
    }

    protected function pickFifoBatch(int $warehouseId, int $productId, float $requiredQty): ?string
    {
        $level = StockLevel::query()
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->where('quantity', '>=', $requiredQty - 0.0001)
            ->where('quantity', '>', 0)
            ->orderBy('id')
            ->first();

        return $level ? (string) $level->batch_no : null;
    }

    protected function firstFifoBatch(int $warehouseId, int $productId): ?string
    {
        $level = StockLevel::query()
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->where('quantity', '>', 0)
            ->orderBy('id')
            ->first();

        return $level ? (string) $level->batch_no : null;
    }

    protected function adjustSingleBatchLevel(
        int $warehouseId,
        Product $product,
        string $batch,
        float $quantityDelta,
        string $type,
        User $user,
        array $meta
    ): StockMovement {
        $level = StockLevel::query()->firstOrCreate(
            [
                'warehouse_id' => $warehouseId,
                'product_id' => $product->id,
                'batch_no' => $batch,
            ],
            ['quantity' => 0]
        );

        $newQty = round((float) $level->quantity + $quantityDelta, 3);

        if ($newQty < -0.0001) {
            throw $this->insufficientStockException(
                $product,
                $warehouseId,
                abs($quantityDelta),
                (float) $level->quantity,
                $batch !== '' ? $batch : null
            );
        }

        $level->update(['quantity' => max(0, $newQty)]);

        $movement = StockMovement::query()->create([
            'movement_number' => $this->nextMovementNumber(),
            'movement_date' => $meta['movement_date'] ?? now()->toDateString(),
            'type' => $type,
            'warehouse_id' => $warehouseId,
            'product_id' => $product->id,
            'quantity' => $quantityDelta,
            'unit_cost' => $meta['unit_cost'] ?? $product->cost_price ?? 0,
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
    }

    protected function deductAcrossBatches(
        int $warehouseId,
        Product $product,
        float $requiredQty,
        string $type,
        User $user,
        array $meta
    ): StockMovement {
        $levels = StockLevel::query()
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $product->id)
            ->where('quantity', '>', 0)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $available = round((float) $levels->sum('quantity'), 3);

        if ($requiredQty > $available + 0.0001) {
            throw $this->insufficientStockException($product, $warehouseId, $requiredQty, $available);
        }

        $remaining = $requiredQty;
        $lastMovement = null;

        foreach ($levels as $level) {
            if ($remaining <= 0.0001) {
                break;
            }

            $take = min((float) $level->quantity, $remaining);
            $remaining = round($remaining - $take, 3);

            $lastMovement = $this->adjustSingleBatchLevel(
                $warehouseId,
                $product,
                (string) $level->batch_no,
                -$take,
                $type,
                $user,
                array_merge($meta, ['batch_no' => $level->batch_no ?: null])
            );
        }

        return $lastMovement ?? throw $this->insufficientStockException($product, $warehouseId, $requiredQty, $available);
    }

    protected function insufficientStockException(
        Product $product,
        int $warehouseId,
        float $requiredQty,
        float $availableQty,
        ?string $batchNo = null
    ): ValidationException {
        $warehouse = Warehouse::query()->find($warehouseId);
        $warehouseName = $warehouse?->name ?? 'غير محدد';

        $message = sprintf(
            'الصنف %s: المطلوب %s، المتاح %s في المخزن %s',
            $product->name,
            $this->formatQty($requiredQty),
            $this->formatQty($availableQty),
            $warehouseName,
        );

        if ($batchNo && $product->track_batch) {
            $message .= sprintf(' (دفعة %s)', $batchNo);
        }

        if ($availableQty <= 0) {
            $inWarehouse = $this->stockBreakdown($product->id, $warehouseId);

            if ($batchNo && $product->track_batch && count($inWarehouse) > 0) {
                $parts = array_map(
                    fn (array $row) => sprintf(
                        'دفعة %s: %s',
                        $row['batch_no'] !== '' ? $row['batch_no'] : '—',
                        $this->formatQty($row['quantity']),
                    ),
                    $inWarehouse,
                );
                $message .= ' — المتوفر في '.$warehouseName.': '.implode('، ', $parts);
            } elseif (count($inWarehouse) === 0) {
                $elsewhere = array_values(array_filter(
                    $this->stockBreakdown($product->id),
                    fn (array $row) => $row['warehouse_id'] !== $warehouseId,
                ));

                if (count($elsewhere) > 0) {
                    $parts = array_map(
                        fn (array $row) => $this->formatStockLocation($row),
                        $elsewhere,
                    );
                    $message .= ' — لا يوجد رصيد في '.$warehouseName.'. المتوفر في: '.implode('؛ ', $parts);
                }
            }

            $draftPurchases = \App\Models\PurchaseInvoice::query()
                ->where('warehouse_id', $warehouseId)
                ->where('status', 'draft')
                ->whereHas('lines', fn ($q) => $q->where('product_id', $product->id))
                ->exists();

            if ($draftPurchases) {
                $message .= ' — يجب ترحيل فاتورة المشتريات أولاً لإضافة الكمية للمخزن.';
            }
        }

        return ValidationException::withMessages(['quantity' => [$message]]);
    }

    protected function formatQty(float $qty): string
    {
        return rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.');
    }

    protected function formatStockLocation(array $row): string
    {
        $part = $row['warehouse_name'].': '.$this->formatQty($row['quantity']);

        if ($row['batch_no'] !== '') {
            $part .= ' (دفعة '.$row['batch_no'].')';
        }

        return $part;
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
