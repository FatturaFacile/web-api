<?php

declare(strict_types=1);

namespace Modules\Procurement\Models;

use App\Domain\Procurement\PurchaseRequisition\Enums\DocumentLinkTargetType;
use App\Domain\Procurement\PurchaseRequisition\Models\PurchaseRequisitionDocumentLink;
use App\Enums\PurchaseOrderStatus;
use App\Models\User;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Modules\Procurement\Services\ProcurementApprovalService;
use Modules\Workflow\Contracts\ApprovableDocument;
use Modules\Workflow\Contracts\LockableByWorkflowInterface;
use Modules\Workflow\Contracts\ReactsToApprovalRestart;
use Modules\Workflow\Contracts\ReactsToWorkflowOutcome;
use Modules\Workflow\Enums\ApprovalOutcome;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Support\FunctionalAmount;

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
 * @property string|null $sales_order_id
 * @property bool $is_dropship
 * @property string|null $source_type
 * @property string|null $source_id
 * @property string|null $dispatch_channel
 * @property string|null $supplier_confirmation_number
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $promised_delivery_date
 * @property Carbon|null $exchange_rate_date
 * @property string|null $exchange_rate_source
 * @property int $reminder_count
 * @property Carbon|null $last_reminder_at
 */
final class PurchaseOrder extends BaseEnterpriseModel implements ApprovableDocument, LockableByWorkflowInterface, ReactsToApprovalRestart, ReactsToWorkflowOutcome
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
            'is_dropship' => 'boolean',
            'confirmed_at' => 'datetime',
            'promised_delivery_date' => 'date',
            'exchange_rate_date' => 'date',
            'reminder_count' => 'integer',
            'last_reminder_at' => 'datetime',
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
    /**
     * {@inheritDoc}
     */
    public function approvalSubjectType(): string
    {
        return 'purchase_order';
    }

    /**
     * {@inheritDoc}
     *
     * The amount is the order total; a foreign-currency order is converted with the rate stored on the order.
     */
    public function approvalAttributes(): array
    {
        $amount = (float) ($this->total_amount ?? $this->total_gross ?? 0);

        return array_filter([
            'amount' => $amount,
            'amount_functional' => FunctionalAmount::of($amount, $this->currency, $this->exchange_rate),
            'currency' => $this->currency,
            'exchange_rate' => $this->exchange_rate,
            'cost_center_id' => $this->cost_center_id,
            'company_id' => $this->company_id,
            'supplier_id' => $this->supplier_id,
            'order_type' => $this->order_type,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * {@inheritDoc}
     */
    public function requesterId(): ?string
    {
        return $this->created_by !== null ? (string) $this->created_by : null;
    }

    /**
     * {@inheritDoc}
     *
     * The engine reaches an order through `onWorkflowOutcome`; this entry point serves callers that use the typed contract.
     */
    public function onApprovalCompleted(ApprovalOutcome $outcome): void
    {
        app(ProcurementApprovalService::class)->applyOutcome($this, null, match ($outcome) {
            ApprovalOutcome::Approved => 'approved',
            ApprovalOutcome::Rejected, ApprovalOutcome::ChangesRequested => 'rejected',
            default => 'cancelled',
        });
    }

    /**
     * {@inheritDoc}
     *
     * An approved order whose amount, supplier or cost centre changed waits for approval again and is locked.
     */
    public function onApprovalRestarted(WorkflowInstance $instance): void
    {
        $this->forceFill([
            'status' => PurchaseOrderStatus::PendingApproval->value,
            'is_locked' => true,
            'approved_at' => null,
            'workflow_instance_id' => $instance->id,
        ])->saveQuietly();
    }

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

    /**
     * @return HasMany<PurchaseRequisitionDocumentLink, $this>
     */
    public function sourceRequisitionLinks(): HasMany
    {
        return $this->hasMany(PurchaseRequisitionDocumentLink::class, 'target_document_id')
            ->where('target_document_type', DocumentLinkTargetType::PoMod046->value);
    }

    /**
     * @return HasMany<PurchaseOrderConfirmation, $this>
     */
    public function confirmations(): HasMany
    {
        return $this->hasMany(PurchaseOrderConfirmation::class, 'purchase_order_id');
    }

    /**
     * @return HasMany<PurchaseOrderDispatch, $this>
     */
    public function dispatches(): HasMany
    {
        return $this->hasMany(PurchaseOrderDispatch::class, 'purchase_order_id');
    }

    /**
     * @return HasMany<PurchaseOrderReminder, $this>
     */
    public function reminders(): HasMany
    {
        return $this->hasMany(PurchaseOrderReminder::class, 'purchase_order_id');
    }

    /**
     * @return HasOne<PurchaseOrderDropship, $this>
     */
    public function dropship(): HasOne
    {
        return $this->hasOne(PurchaseOrderDropship::class, 'purchase_order_id');
    }
}
