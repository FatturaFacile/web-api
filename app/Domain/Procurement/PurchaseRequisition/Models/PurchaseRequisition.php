<?php

declare(strict_types=1);

namespace App\Domain\Procurement\PurchaseRequisition\Models;

use App\Domain\Audit\Concerns\HasImmutableAuditTrail;
use App\Domain\Audit\Contracts\AuditableEntityInterface;
use App\Domain\Procurement\PurchaseRequisition\Enums\RequisitionStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Projects\Models\Project;
use Modules\Workflow\Models\WorkflowInstance;

/**
 * Aggregate root for Module 044 (Purchase Requisitions / RDA / PR) & Internal Approval Workflow.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string|null $company_id
 * @property string|null $branch_id
 * @property string|null $department_id
 * @property string|null $cost_center_id
 * @property string|null $project_id
 * @property string $requisition_number
 * @property string $title
 * @property string $requestor_user_id
 * @property string|null $requester_user_id
 * @property string $urgency
 * @property Carbon|null $required_by_date
 * @property Carbon|null $requested_delivery_date
 * @property string|null $economic_justification
 * @property string $currency
 * @property string $total_estimated_amount
 * @property string $status
 * @property array<string, mixed>|null $budget_check_snapshot
 * @property bool $budget_warning_acknowledged
 * @property int|null $current_approval_level
 * @property string|null $notes
 * @property bool $is_locked
 * @property string|null $workflow_instance_id
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $approved_at
 * @property CarbonImmutable|null $rejected_at
 * @property string|null $rejection_reason
 * @property CarbonImmutable|null $cancelled_at
 * @property CarbonImmutable|null $converted_at
 * @property string|null $created_by
 * @property string|null $updated_by
 * @property string|null $deleted_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, PurchaseRequisitionLine> $lines
 * @property-read Collection<int, PurchaseRequisitionApprovalLog> $approvalLogs
 * @property-read Collection<int, PurchaseRequisitionDocumentLink> $documentLinks
 * @property-read User|null $requester
 * @property-read Project|null $project
 * @property-read WorkflowInstance|null $workflowInstance
 */
final class PurchaseRequisition extends BaseEnterpriseModel implements AuditableEntityInterface
{
    use HasImmutableAuditTrail;

    protected $table = 'purchase_requisitions';

    protected static function booted(): void
    {
        self::saving(static function (PurchaseRequisition $requisition): void {
            if ($requisition->requester_user_id !== null && $requisition->requester_user_id !== '') {
                $requisition->requestor_user_id = (string) $requisition->requester_user_id;
            } elseif ($requisition->requestor_user_id !== null && $requisition->requestor_user_id !== '') {
                $requisition->requester_user_id = (string) $requisition->requestor_user_id;
            }

            if ($requisition->requested_delivery_date !== null) {
                $requisition->required_by_date = $requisition->requested_delivery_date;
            } elseif ($requisition->required_by_date !== null) {
                $requisition->requested_delivery_date = $requisition->required_by_date;
            } else {
                $defaultDate = now()->addDays(14)->toDateString();
                $requisition->required_by_date = $defaultDate;
            }

            $title = trim((string) ($requisition->title ?? ''));
            if ($title === '') {
                $justification = trim((string) ($requisition->economic_justification ?? ''));
                $requisition->title = $justification !== ''
                    ? Str::limit($justification, 120, '')
                    : ($requisition->requisition_number !== '' ? $requisition->requisition_number : 'Purchase Requisition');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'required_by_date' => 'date',
            'requested_delivery_date' => 'date',
            'total_estimated_amount' => 'decimal:2',
            'budget_check_snapshot' => 'array',
            'budget_warning_acknowledged' => 'boolean',
            'current_approval_level' => 'integer',
            'is_locked' => 'boolean',
            'submitted_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'rejected_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'converted_at' => 'immutable_datetime',
        ];
    }

    public function statusEnum(): RequisitionStatus
    {
        return RequisitionStatus::tryFrom((string) $this->status) ?? RequisitionStatus::Draft;
    }

    public function effectiveRequesterId(): string
    {
        return (string) ($this->requester_user_id ?? $this->requestor_user_id ?? $this->created_by ?? '');
    }

    /**
     * @return HasMany<PurchaseRequisitionLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseRequisitionLine::class, 'purchase_requisition_id')->orderBy('line_number');
    }

    /**
     * @return HasMany<PurchaseRequisitionApprovalLog, $this>
     */
    public function approvalLogs(): HasMany
    {
        return $this->hasMany(PurchaseRequisitionApprovalLog::class, 'purchase_requisition_id')
            ->orderBy('acted_at')
            ->orderBy('id');
    }

    /**
     * @return HasMany<PurchaseRequisitionDocumentLink, $this>
     */
    public function documentLinks(): HasMany
    {
        return $this->hasMany(PurchaseRequisitionDocumentLink::class, 'purchase_requisition_id')
            ->orderBy('created_at');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /**
     * @return BelongsTo<WorkflowInstance, $this>
     */
    public function workflowInstance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class, 'workflow_instance_id');
    }
}
