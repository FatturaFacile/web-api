<?php

declare(strict_types=1);

namespace Modules\Procurement\Models;

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
 * An internal request to buy goods or services, approved through the workflow engine and then sourced through a
 * request for quotation or turned straight into purchase orders.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $company_id
 * @property string|null $branch_id
 * @property string|null $department_id
 * @property string|null $cost_center_id
 * @property string|null $project_id
 * @property string $requisition_number
 * @property string $title
 * @property string $requestor_user_id
 * @property string $urgency
 * @property Carbon|null $required_by_date
 * @property string $status
 * @property string|null $total_estimated_amount
 * @property string $currency
 * @property string|null $notes
 * @property bool $is_locked
 * @property string|null $workflow_instance_id
 * @property Carbon|null $submitted_at
 * @property Carbon|null $approved_at
 * @property string|null $created_by
 */
final class PurchaseRequisition extends BaseEnterpriseModel implements LockableByWorkflowInterface, ReactsToWorkflowOutcome
{
    protected $table = 'purchase_requisitions';

    protected function casts(): array
    {
        return [
            'required_by_date' => 'date',
            'total_estimated_amount' => 'decimal:2',
            'is_locked' => 'boolean',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
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

    /**
     * Applies the outcome of the approval workflow in the transaction of the decision.
     */
    public function onWorkflowOutcome(WorkflowInstance $instance, string $outcome): void
    {
        app(ProcurementApprovalService::class)->applyOutcome($this, $instance, $outcome);
    }

    /**
     * @return HasMany<PurchaseRequisitionLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseRequisitionLine::class, 'purchase_requisition_id')->orderBy('line_number');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requestor_user_id');
    }

    /**
     * @return HasMany<PurchaseOrder, $this>
     */
    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class, 'purchase_requisition_id');
    }
}
