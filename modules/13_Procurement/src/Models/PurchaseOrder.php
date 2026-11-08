<?php

declare(strict_types=1);

namespace Modules\Procurement\Models;

use App\Enums\PurchaseOrderStatus;
use App\Models\User;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Procurement\Services\ProcurementApprovalService;
use Modules\Workflow\Contracts\LockableByWorkflowInterface;
use Modules\Workflow\Contracts\ReactsToWorkflowOutcome;
use Modules\Workflow\Models\WorkflowInstance;

/**
 * A purchase order of the procurement module. The table is shared with the purchasing API of the app: both write the
 * statuses of App\Enums\PurchaseOrderStatus and take their numbers from the same gapless `purchase_order` series.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $company_id
 * @property string|null $branch_id
 * @property string|null $department_id
 * @property string|null $cost_center_id
 * @property string|null $project_id
 * @property string $supplier_id
 * @property string|null $supplier_contract_id
 * @property string|null $purchase_requisition_id
 * @property string|null $rfq_id
 * @property string|null $po_number
 * @property string $order_number
 * @property string|null $order_type
 * @property string $status
 * @property Carbon|null $date
 * @property Carbon|null $expected_delivery_date
 * @property Carbon|null $delivery_due_date
 * @property string $currency
 * @property string|null $exchange_rate
 * @property string|null $net_amount
 * @property string|null $tax_amount
 * @property string|null $total_amount
 * @property string|null $total_net
 * @property string|null $total_tax
 * @property string|null $total_gross
 * @property string|null $budget_committed_amount
 * @property string|null $payment_terms_code
 * @property string|null $incoterms
 * @property array<string, mixed>|null $shipping_address
 * @property string|null $notes
 * @property bool $is_budget_encumbered
 * @property bool $is_locked
 * @property string|null $workflow_instance_id
 * @property Carbon|null $submitted_at
 * @property Carbon|null $approved_at
 * @property string|null $approved_by
 * @property Carbon|null $sent_at
 * @property Carbon|null $closed_at
 * @property string|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $order_date
 */
final class PurchaseOrder extends BaseEnterpriseModel implements LockableByWorkflowInterface, ReactsToWorkflowOutcome
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
            'total_net' => 'decimal:2',
            'total_tax' => 'decimal:2',
            'total_gross' => 'decimal:2',
            'budget_committed_amount' => 'decimal:2',
            'shipping_address' => 'array',
            'is_budget_encumbered' => 'boolean',
            'is_locked' => 'boolean',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'sent_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * The status as the shared enum; null for a value outside the vocabulary.
     */
    public function statusEnum(): ?PurchaseOrderStatus
    {
        return PurchaseOrderStatus::tryFrom((string) $this->status);
    }

    public function lockByWorkflow(WorkflowInstance $instance): void
    {
        $this->update(['is_locked' => true]);
    }

    public function isLockedByWorkflow(): bool
    {
        return (bool) $this->is_locked;
    }

    /**
     * Applies the outcome of the approval workflow in the transaction of the decision.
     */
    public function onWorkflowOutcome(WorkflowInstance $instance, string $outcome): void
    {
        app(ProcurementApprovalService::class)->applyOutcome($this, $instance, $outcome);
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    /**
     * @return BelongsTo<SupplierContract, $this>
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(SupplierContract::class, 'supplier_contract_id');
    }

    /**
     * @return BelongsTo<PurchaseRequisition, $this>
     */
    public function requisition(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequisition::class, 'purchase_requisition_id');
    }

    /**
     * @return HasMany<PurchaseOrderLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class, 'purchase_order_id')->orderBy('line_number');
    }

    /**
     * @return HasMany<PurchaseReceipt, $this>
     */
    public function receipts(): HasMany
    {
        return $this->hasMany(PurchaseReceipt::class, 'purchase_order_id');
    }

    /**
     * @return HasMany<PurchaseReturn, $this>
     */
    public function returns(): HasMany
    {
        return $this->hasMany(PurchaseReturn::class, 'purchase_order_id');
    }

    /**
     * @return HasMany<ThreeWayMatchLog, $this>
     */
    public function matchLogs(): HasMany
    {
        return $this->hasMany(ThreeWayMatchLog::class, 'purchase_order_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
