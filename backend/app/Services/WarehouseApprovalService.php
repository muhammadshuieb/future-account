<?php

namespace App\Services;

use App\Models\InventoryCount;
use App\Models\Product;
use App\Models\StockLevel;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseApprovalRequest;
use App\Models\WarehouseTransfer;
use App\Support\ProductSku;
use App\Support\WarehouseAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WarehouseApprovalService
{
    public function __construct(
        protected InventoryService $inventory,
        protected AuditLogger $audit,
        protected AppNotificationService $notifications,
    ) {}

    /**
     * @param  list<int>  $warehouseIds
     */
    public function request(
        User $requester,
        string $permission,
        string $actionType,
        array $payload,
        array $warehouseIds,
        ?Model $target = null,
    ): WarehouseApprovalRequest {
        if (! WarehouseAccess::isScoped($requester) || ! $requester->can($permission)) {
            abort(403, 'ليس لديك صلاحية طلب هذه العملية.');
        }

        $warehouseIds = array_values(array_unique(array_map('intval', $warehouseIds)));
        if ($warehouseIds === []) {
            throw ValidationException::withMessages(['warehouse_id' => ['يجب تحديد مخزن للعملية.']]);
        }
        foreach ($warehouseIds as $warehouseId) {
            WarehouseAccess::assertWarehouse($requester, $warehouseId);
        }

        $request = WarehouseApprovalRequest::query()->create([
            'requester_id' => $requester->id,
            'warehouse_id' => $warehouseIds[0],
            'action_type' => $actionType,
            'target_type' => $target ? $target::class : null,
            'target_id' => $target?->getKey(),
            'before_payload' => $target?->getAttributes(),
            'after_payload' => $payload,
            'source_hash' => $target ? $this->sourceHash($target) : null,
            'status' => 'pending',
        ]);

        $this->audit->log($requester, 'warehouse_approval.requested', $request, null, [
            'action_type' => $actionType,
            'warehouse_ids' => $warehouseIds,
            'payload' => $payload,
        ]);
        $this->notifications->notifyAdmins(
            'warehouse_approval_pending',
            'طلب مخزن بانتظار الموافقة',
            "{$requester->name}: {$actionType}",
            ['href' => '/warehouse-approvals', 'request_id' => $request->id],
        );

        return $request->load(['requester:id,name', 'warehouse:id,name']);
    }

    public function approve(WarehouseApprovalRequest $approvalRequest, User $reviewer, ?string $comment = null): WarehouseApprovalRequest
    {
        if ($approvalRequest->requester_id === $reviewer->id) {
            throw ValidationException::withMessages(['reviewer' => ['لا يمكن لمقدم الطلب الموافقة على طلبه.']]);
        }

        return DB::transaction(function () use ($approvalRequest, $reviewer, $comment) {
            $request = WarehouseApprovalRequest::query()->lockForUpdate()->findOrFail($approvalRequest->id);
            if ($request->status !== 'pending') {
                throw ValidationException::withMessages(['status' => ['تمت مراجعة هذا الطلب مسبقاً.']]);
            }

            $result = $this->apply($request, $reviewer);
            $request->update([
                'status' => 'approved',
                'reviewer_id' => $reviewer->id,
                'reviewed_at' => now(),
                'review_comment' => $comment,
            ]);

            $this->audit->log($reviewer, 'warehouse_approval.approved', $request, $request->before_payload, [
                'action_type' => $request->action_type,
                'applied' => $result,
                'comment' => $comment,
            ]);
            $this->notifications->notifyUser(
                $request->requester,
                'warehouse_approval_reviewed',
                'تمت الموافقة على طلب المخزن',
                $request->action_type,
                ['request_id' => $request->id],
            );

            return $request->fresh(['requester:id,name', 'reviewer:id,name', 'warehouse:id,name']);
        });
    }

    public function reject(WarehouseApprovalRequest $approvalRequest, User $reviewer, string $comment): WarehouseApprovalRequest
    {
        if ($approvalRequest->requester_id === $reviewer->id) {
            throw ValidationException::withMessages(['reviewer' => ['لا يمكن لمقدم الطلب مراجعة طلبه.']]);
        }

        return DB::transaction(function () use ($approvalRequest, $reviewer, $comment) {
            $request = WarehouseApprovalRequest::query()->lockForUpdate()->findOrFail($approvalRequest->id);
            if ($request->status !== 'pending') {
                throw ValidationException::withMessages(['status' => ['تمت مراجعة هذا الطلب مسبقاً.']]);
            }

            $request->update([
                'status' => 'rejected',
                'reviewer_id' => $reviewer->id,
                'reviewed_at' => now(),
                'review_comment' => $comment,
            ]);
            $this->audit->log($reviewer, 'warehouse_approval.rejected', $request, null, [
                'action_type' => $request->action_type,
                'comment' => $comment,
            ]);
            $this->notifications->notifyUser(
                $request->requester,
                'warehouse_approval_reviewed',
                'تم رفض طلب المخزن',
                $comment,
                ['request_id' => $request->id],
            );

            return $request->fresh(['requester:id,name', 'reviewer:id,name', 'warehouse:id,name']);
        });
    }

    protected function apply(WarehouseApprovalRequest $request, User $reviewer): array
    {
        $payload = $request->after_payload;

        return match ($request->action_type) {
            'product.create' => $this->createProduct($payload, $reviewer),
            'product.update' => $this->updateProduct($request, $payload),
            'stock.adjustment.create' => $this->modelResult($this->inventory->createManualMovement($payload, $reviewer)),
            'warehouse_transfer.create' => $this->createTransfer($payload, $reviewer),
            'warehouse_transfer.post' => $this->postTransfer($request, $reviewer),
            'inventory_count.create' => $this->createCount($payload, $reviewer),
            'inventory_count.post' => $this->postCount($request, $reviewer),
            'warehouse.update' => $this->updateWarehouse($request, $payload),
            default => throw ValidationException::withMessages(['action_type' => ['نوع طلب غير مدعوم.']]),
        };
    }

    protected function createProduct(array $payload, User $reviewer): array
    {
        $sku = trim((string) ($payload['sku'] ?? ''));
        if ($sku === '') {
            $payload['sku'] = ProductSku::next();
        } elseif (Product::query()->where('sku', $sku)->exists()) {
            throw ValidationException::withMessages(['sku' => ['رمز الصنف مستخدم منذ تقديم الطلب.']]);
        }
        if (! empty($payload['barcode']) && Product::query()->where('barcode', $payload['barcode'])->exists()) {
            throw ValidationException::withMessages(['barcode' => ['الباركود مستخدم منذ تقديم الطلب.']]);
        }

        $warehouseId = (int) $payload['warehouse_id'];
        $openingQty = round((float) ($payload['opening_quantity'] ?? 0), 3);
        $productData = Arr::except($payload, ['warehouse_id', 'opening_quantity']);

        $product = Product::query()->create($productData);
        if ($openingQty > 0) {
            $this->inventory->adjustStock($warehouseId, $product->id, $openingQty, 'in', $reviewer, [
                'unit_cost' => $product->cost_price,
                'notes' => 'رصيد افتتاحي بعد موافقة الإدارة',
            ]);
        } else {
            StockLevel::query()->firstOrCreate([
                'warehouse_id' => $warehouseId,
                'product_id' => $product->id,
                'batch_no' => '',
            ], ['quantity' => 0]);
        }

        return $this->modelResult($product);
    }

    protected function updateProduct(WarehouseApprovalRequest $request, array $payload): array
    {
        $product = Product::query()->lockForUpdate()->findOrFail($request->target_id);
        $this->assertFresh($request, $product);
        if (Product::query()->where('sku', $payload['sku'])->where('id', '!=', $product->id)->exists()) {
            throw ValidationException::withMessages(['sku' => ['رمز الصنف مستخدم منذ تقديم الطلب.']]);
        }
        if (! empty($payload['barcode'])
            && Product::query()->where('barcode', $payload['barcode'])->where('id', '!=', $product->id)->exists()) {
            throw ValidationException::withMessages(['barcode' => ['الباركود مستخدم منذ تقديم الطلب.']]);
        }
        $product->update($payload);

        return $this->modelResult($product->fresh());
    }

    protected function updateWarehouse(WarehouseApprovalRequest $request, array $payload): array
    {
        $warehouse = Warehouse::query()->lockForUpdate()->findOrFail($request->target_id);
        $this->assertFresh($request, $warehouse);
        if (Warehouse::query()->where('code', $payload['code'])->where('id', '!=', $warehouse->id)->exists()) {
            throw ValidationException::withMessages(['code' => ['رمز المخزن مستخدم منذ تقديم الطلب.']]);
        }
        $warehouse->update($payload);

        return $this->modelResult($warehouse->fresh());
    }

    protected function createTransfer(array $payload, User $reviewer): array
    {
        $sourceId = (int) $payload['from_warehouse_id'];
        $targetId = (int) $payload['to_warehouse_id'];

        Warehouse::query()->lockForUpdate()->findOrFail($sourceId);
        $target = Warehouse::query()->lockForUpdate()->findOrFail($targetId);
        if (! $target->is_active) {
            throw ValidationException::withMessages([
                'to_warehouse_id' => ['المخزن الهدف لم يعد نشطاً.'],
            ]);
        }

        foreach ($payload['lines'] as $line) {
            $product = Product::query()->findOrFail((int) $line['product_id']);
            StockLevel::query()
                ->where('warehouse_id', $sourceId)
                ->where('product_id', $product->id)
                ->lockForUpdate()
                ->get();
            $this->inventory->assertSufficientStock(
                $sourceId,
                $product->id,
                (float) $line['quantity'],
                $line['batch_no'] ?? null,
                $product,
            );
        }

        return $this->modelResult(
            $this->inventory->transfer($payload, $payload['lines'], $reviewer)
        );
    }

    protected function postTransfer(WarehouseApprovalRequest $request, User $reviewer): array
    {
        $transfer = WarehouseTransfer::query()->lockForUpdate()->findOrFail($request->target_id);
        $this->assertFresh($request, $transfer);

        return $this->modelResult($this->inventory->postTransfer($transfer, $reviewer));
    }

    protected function createCount(array $payload, User $reviewer): array
    {
        $count = InventoryCount::query()->create([
            'count_number' => $this->inventory->nextNumber('CNT'),
            'warehouse_id' => $payload['warehouse_id'],
            'count_date' => $payload['count_date'],
            'status' => 'draft',
            'notes' => $payload['notes'] ?? null,
            'created_by' => $reviewer->id,
        ]);

        foreach ($payload['lines'] as $line) {
            $query = StockLevel::query()
                ->where('warehouse_id', $payload['warehouse_id'])
                ->where('product_id', $line['product_id']);
            if (! empty($line['batch_no'])) {
                $query->where('batch_no', $line['batch_no']);
            }
            $system = (float) $query->sum('quantity');
            $counted = (float) $line['counted_qty'];
            $count->lines()->create([
                'product_id' => $line['product_id'],
                'system_qty' => $system,
                'counted_qty' => $counted,
                'difference' => round($counted - $system, 3),
                'batch_no' => $line['batch_no'] ?? null,
                'serial_no' => $line['serial_no'] ?? null,
            ]);
        }

        return $this->modelResult($count);
    }

    protected function postCount(WarehouseApprovalRequest $request, User $reviewer): array
    {
        $count = InventoryCount::query()->lockForUpdate()->findOrFail($request->target_id);
        $this->assertFresh($request, $count);

        return $this->modelResult($this->inventory->postInventoryCount($count, $reviewer));
    }

    protected function assertFresh(WarehouseApprovalRequest $request, Model $model): void
    {
        if (! hash_equals((string) $request->source_hash, $this->sourceHash($model))) {
            throw ValidationException::withMessages([
                'conflict' => ['تغير السجل منذ تقديم الطلب. راجع التغييرات وقدّم طلباً جديداً.'],
            ]);
        }
    }

    public function sourceHash(Model $model): string
    {
        $attributes = $model->getAttributes();
        ksort($attributes);

        return hash('sha256', json_encode($attributes, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    protected function modelResult(Model $model): array
    {
        return ['type' => $model::class, 'id' => $model->getKey()];
    }
}
