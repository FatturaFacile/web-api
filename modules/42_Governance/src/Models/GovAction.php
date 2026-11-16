<?php

declare(strict_types=1);

namespace Modules\Governance\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A remediation action of a finding with an owner and a due date. The owner completes it, somebody else verifies it.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $finding_id
 * @property string $code
 * @property string $title
 * @property string|null $description
 * @property string $owner_id
 * @property Carbon $due_date
 * @property string $status
 * @property Carbon|null $completed_at
 * @property string|null $completed_by
 * @property string|null $completion_notes
 * @property Carbon|null $verified_at
 * @property string|null $verified_by
 * @property string|null $verification_notes
 */
final class GovAction extends BaseEnterpriseModel
{
    public const array Statuses = ['open', 'in_progress', 'completed', 'verified', 'cancelled'];

    /** Statuses of an action that is still to be carried out. */
    public const array OpenStatuses = ['open', 'in_progress'];

    /** Statuses that do not hold back the resolution of the finding. */
    public const array SettledStatuses = ['verified', 'cancelled'];

    /**
     * Allowed status changes: from => list of targets.
     *
     * @var array<string, list<string>>
     */
    public const array Transitions = [
        'open' => ['in_progress', 'completed', 'cancelled'],
        'in_progress' => ['completed', 'cancelled'],
        'completed' => ['verified', 'in_progress'],
        'verified' => [],
        'cancelled' => [],
    ];

    /**
     * Longest term, in days from today, an action of a finding of that severity may be given.
     *
     * @var array<string, int>
     */
    public const array MaxDaysBySeverity = ['critical' => 30, 'high' => 90, 'medium' => 180, 'low' => 365];

    protected $table = 'gov_actions';

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'completed_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    public function isOverdue(): bool
    {
        return in_array($this->status, self::OpenStatuses, true) && $this->due_date->endOfDay()->isPast();
    }

    /**
     * @return BelongsTo<GovFinding, $this>
     */
    public function finding(): BelongsTo
    {
        return $this->belongsTo(GovFinding::class, 'finding_id');
    }
}
