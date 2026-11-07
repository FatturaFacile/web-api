<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Associations\Enums\BallotStatus;
use Modules\Associations\Enums\MajorityRule;

/**
 * A question put to the vote during a meeting, open or secret.
 *
 * @property string $id
 * @property string $meeting_id
 * @property list<string> $options
 * @property bool $is_secret
 * @property BallotStatus $status
 * @property MajorityRule $majority_rule
 * @property ?string $qualified_percent
 * @property ?array<string, mixed> $results
 * @property-read Meeting $meeting
 */
final class Ballot extends AssociationRecord
{
    protected $table = 'assoc_ballots';

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'is_secret' => 'boolean',
            'status' => BallotStatus::class,
            'majority_rule' => MajorityRule::class,
            'qualified_percent' => 'decimal:2',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'results' => 'array',
        ];
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class, 'meeting_id');
    }
}
