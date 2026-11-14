<?php

declare(strict_types=1);

namespace Modules\Governance\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A finding from an audit, a failed control test or a self-assessment, with severity, recommendation and the
 * remediation actions that fix it. It is resolved when every action is verified and closed after a final check.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $audit_id
 * @property string|null $control_id
 * @property string|null $risk_id
 * @property string $code
 * @property string $source
 * @property string $title
 * @property string|null $description
 * @property string $severity
 * @property string|null $recommendation
 * @property string|null $owner_id
 * @property string $status
 * @property string|null $accepted_reason
 * @property Carbon|null $accepted_at
 * @property string|null $accepted_by
 * @property Carbon|null $closed_at
 * @property string|null $closed_by
 * @property string|null $closure_notes
 */
final class GovFinding extends BaseEnterpriseModel
{
    public const array Sources = ['audit', 'control_test', 'self_assessment', 'external'];

    public const array Severities = ['low', 'medium', 'high', 'critical'];

    public const array Statuses = ['open', 'remediation', 'resolved', 'closed', 'risk_accepted'];

    /** Statuses of a finding that still needs work. */
    public const array OpenStatuses = ['open', 'remediation', 'resolved'];

    /**
     * Allowed status changes: from => list of targets.
     *
     * @var array<string, list<string>>
     */
    public const array Transitions = [
        'open' => ['remediation', 'risk_accepted'],
        'remediation' => ['resolved', 'risk_accepted', 'open'],
        'resolved' => ['closed', 'remediation'],
        'closed' => ['open'],
        'risk_accepted' => ['open', 'closed'],
    ];

    protected $table = 'gov_findings';

    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OpenStatuses, true);
    }

    /**
     * @return BelongsTo<GovAudit, $this>
     */
    public function audit(): BelongsTo
    {
        return $this->belongsTo(GovAudit::class, 'audit_id');
    }

    /**
     * @return BelongsTo<GovControl, $this>
     */
    public function control(): BelongsTo
    {
        return $this->belongsTo(GovControl::class, 'control_id');
    }

    /**
     * @return BelongsTo<GovRisk, $this>
     */
    public function risk(): BelongsTo
    {
        return $this->belongsTo(GovRisk::class, 'risk_id');
    }

    /**
     * @return HasMany<GovAction, $this>
     */
    public function actions(): HasMany
    {
        return $this->hasMany(GovAction::class, 'finding_id');
    }
}
