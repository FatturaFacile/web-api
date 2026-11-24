<?php

declare(strict_types=1);

namespace Modules\Governance\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An internal audit with scope, team and dates. Planned, then in progress (fieldwork), reporting (findings are
 * drafted) and closed with a conclusion.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $audit_plan_id
 * @property string $code
 * @property string $title
 * @property string $audit_type
 * @property string $scope
 * @property string|null $objectives
 * @property string $lead_auditor_id
 * @property list<string>|null $team_member_ids
 * @property Carbon $planned_start
 * @property Carbon $planned_end
 * @property Carbon|null $actual_start
 * @property Carbon|null $actual_end
 * @property string $status
 * @property string|null $conclusion
 */
final class GovAudit extends BaseEnterpriseModel
{
    public const array Types = ['process', 'compliance', 'it', 'financial', 'follow_up'];

    public const array Statuses = ['planned', 'in_progress', 'reporting', 'closed', 'cancelled'];

    /** Statuses in which findings can be raised. */
    public const array FieldworkStatuses = ['in_progress', 'reporting'];

    /**
     * Allowed status changes: from => list of targets.
     *
     * @var array<string, list<string>>
     */
    public const array Transitions = [
        'planned' => ['in_progress', 'cancelled'],
        'in_progress' => ['reporting', 'cancelled'],
        'reporting' => ['closed', 'in_progress'],
        'closed' => [],
        'cancelled' => [],
    ];

    protected $table = 'gov_audits';

    protected function casts(): array
    {
        return [
            'team_member_ids' => 'array',
            'planned_start' => 'date',
            'planned_end' => 'date',
            'actual_start' => 'date',
            'actual_end' => 'date',
        ];
    }

    public function isLateToStart(): bool
    {
        return $this->status === 'planned' && $this->planned_start->endOfDay()->isPast();
    }

    /**
     * @return BelongsTo<GovAuditPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(GovAuditPlan::class, 'audit_plan_id');
    }

    /**
     * @return HasMany<GovFinding, $this>
     */
    public function findings(): HasMany
    {
        return $this->hasMany(GovFinding::class, 'audit_id');
    }
}
