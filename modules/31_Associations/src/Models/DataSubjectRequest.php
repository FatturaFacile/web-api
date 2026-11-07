<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A request of a data subject to exercise a right, with the deadline to answer and the outcome.
 *
 * @property string $id
 * @property string $kind
 * @property string $status
 * @property ?string $member_id
 * @property ?Member $member
 */
final class DataSubjectRequest extends AssociationRecord
{
    public const array Kinds = ['access', 'rectification', 'erasure', 'portability', 'restriction', 'objection'];

    public const array OpenStatuses = ['received', 'in_progress', 'extended'];

    protected $table = 'assoc_data_subject_requests';

    protected function casts(): array
    {
        return [
            'requester_name' => 'encrypted',
            'requester_contact' => 'encrypted',
            'details' => 'encrypted',
            'outcome' => 'encrypted',
            'received_at' => 'datetime',
            'due_on' => 'date',
            'extended_due_on' => 'date',
            'completed_at' => 'datetime',
            'erasure_report' => 'array',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }
}
