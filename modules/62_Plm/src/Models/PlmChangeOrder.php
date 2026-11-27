<?php

declare(strict_types=1);

namespace Modules\Plm\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Plm\Enums\ApprovalDepartment;
use Modules\Plm\Enums\ChangeReason;
use Modules\Plm\Enums\EcoWorkflowStage;
use Modules\Plm\Enums\StockDispositionRule;

/**
 * EngineeringChangeRequestAndOrder: tracks ECR -> ECO -> ECN workflow, change reason,
 * existing stock disposition rule, cutover date/lot, and departmental electronic approvals.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $title
 * @property string $reason
 * @property string $change_type
 * @property string $priority
 * @property string $status
 * @property string $workflow_stage
 * @property string $change_reason
 * @property bool $is_safety_immediate_cutover
 * @property string $existing_stock_disposition_rule
 * @property string|null $effective_date_or_lot_cutover
 * @property string|null $lot_serial_cutover_code
 * @property array<string, mixed>|null $approvals_matrix_json
 * @property string|null $ecn_number
 * @property Carbon|null $ecn_released_at
 * @property array<string, mixed>|null $cutover_summary_json
 * @property string|null $requested_by
 * @property Carbon|null $effective_date
 * @property Carbon|null $submitted_at
 * @property Carbon|null $decided_at
 * @property Carbon|null $implemented_at
 * @property string|null $rejection_reason
 * @property string|null $notes
 */
final class PlmChangeOrder extends BaseEnterpriseModel
{
    public const string Draft = 'draft';

    public const string InReview = 'in_review';

    public const string Approved = 'approved';

    public const string Rejected = 'rejected';

    public const string Implemented = 'implemented';

    public const string Cancelled = 'cancelled';

    /** @var list<string> */
    public const array Statuses = [self::Draft, self::InReview, self::Approved, self::Rejected, self::Implemented, self::Cancelled];

    /** @var list<string> */
    public const array ChangeTypes = ['design', 'material', 'process', 'documentation'];

    /** @var list<string> */
    public const array Priorities = ['low', 'normal', 'high', 'critical'];

    protected $table = 'plm_change_orders';

    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
            'implemented_at' => 'datetime',
            'ecn_released_at' => 'datetime',
            'is_safety_immediate_cutover' => 'boolean',
            'approvals_matrix_json' => 'array',
            'cutover_summary_json' => 'array',
        ];
    }

    public function workflowStageEnum(): EcoWorkflowStage
    {
        $stage = $this->getAttribute('workflow_stage');
        if (is_string($stage) && $stage !== '') {
            $parsed = EcoWorkflowStage::tryFrom($stage);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        return EcoWorkflowStage::fromLegacyStatus((string) $this->status);
    }

    public function changeReasonEnum(): ChangeReason
    {
        return ChangeReason::tryFrom((string) $this->change_reason) ?? ChangeReason::CostReduction;
    }

    public function dispositionRuleEnum(): StockDispositionRule
    {
        return StockDispositionRule::tryFrom((string) $this->existing_stock_disposition_rule) ?? StockDispositionRule::UseUpEsaurimentoScorte;
    }

    public function isImmediateSafetyCutover(): bool
    {
        return (bool) $this->is_safety_immediate_cutover || $this->changeReasonEnum()->requiresImmediateSafetyCutover();
    }

    public function hasAllDepartmentApprovals(): bool
    {
        $matrix = $this->approvals_matrix_json ?? [];
        foreach (ApprovalDepartment::cases() as $department) {
            $entry = $matrix[$department->value] ?? null;
            if (! is_array($entry) || ($entry['status'] ?? 'pending') !== 'approved') {
                return false;
            }
        }

        return true;
    }

    /**
     * @return HasMany<PlmChangeApproval, $this>
     */
    public function approvals(): HasMany
    {
        return $this->hasMany(PlmChangeApproval::class, 'change_order_id')->orderBy('sequence');
    }

    /**
     * @return HasMany<PlmAffectedItem, $this>
     */
    public function affectedItems(): HasMany
    {
        return $this->hasMany(PlmAffectedItem::class, 'change_order_id');
    }

    /**
     * @return HasMany<PlmAffectedBom, $this>
     */
    public function affectedBoms(): HasMany
    {
        return $this->hasMany(PlmAffectedBom::class, 'change_order_id');
    }
}
