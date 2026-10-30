<?php

declare(strict_types=1);

namespace Modules\Procurement\Models;

use App\Models\User;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Workflow\Contracts\LockableByWorkflowInterface;
use Modules\Workflow\Models\WorkflowInstance;

final class PurchaseOrder extends BaseEnterpriseModel implements LockableByWorkflowInterface
{
    protected $table = 'purchase_orders';

    protected static function booted(): void
    {
        self::saving(function (PurchaseOrder $order): void {
            if (isset($order->attributes['order_date'])) {
                $order->attributes['date'] = $order->attributes['order_date'];
                unset($order->attributes['order_date']);
            }
            if (empty($order->attributes['date'])) {
                $order->attributes['date'] = now()->toDateString();
            }
        });
    }

    public function getOrderDateAttribute(): mixed
    {
        $date = $this->attributes['date'] ?? null;

        return $date !== null ? $this->asDate($date) : null;
    }

    public function setOrderDateAttribute(mixed $value): void
    {
        $this->attributes['date'] = $value;
    }

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'expected_delivery_date' => 'date',
            'exchange_rate' => 'decimal:6',
            'net_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'budget_committed_amount' => 'decimal:2',
            'shipping_address' => 'array',
            'is_budget_encumbered' => 'boolean',
            'is_locked' => 'boolean',
        ];
    }

    public function lockByWorkflow(WorkflowInstance $instance): void
    {
        $this->update(['is_locked' => true]);
    }

    public function isLockedByWorkflow(): bool
    {
        return (bool) $this->is_locked;
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(SupplierContract::class, 'supplier_contract_id');
    }

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequisition::class, 'purchase_requisition_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class, 'purchase_order_id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(PurchaseReceipt::class, 'purchase_order_id');
    }

    public function returns(): HasMany
    {
        return $this->hasMany(PurchaseReturn::class, 'purchase_order_id');
    }

    public function matchLogs(): HasMany
    {
        return $this->hasMany(ThreeWayMatchLog::class, 'purchase_order_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
