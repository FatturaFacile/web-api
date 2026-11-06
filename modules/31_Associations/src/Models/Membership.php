<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Associations\Enums\MembershipStatus;

/**
 * The membership of a person in a year. A renewal is a new row of the next year with the same card number.
 *
 * @property string $id
 * @property MembershipStatus $status
 * @property ?string $card_number
 * @property-read Member $member
 * @property-read AssociativeYear $year
 * @property-read MembershipType $membershipType
 */
final class Membership extends AssociationRecord
{
    protected $table = 'assoc_memberships';

    protected function casts(): array
    {
        return [
            'status' => MembershipStatus::class,
            'issued_on' => 'date',
            'valid_from' => 'date',
            'valid_to' => 'date',
            'ceased_on' => 'date',
            'card_sequence' => 'integer',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    public function year(): BelongsTo
    {
        return $this->belongsTo(AssociativeYear::class, 'year_id');
    }

    public function membershipType(): BelongsTo
    {
        return $this->belongsTo(MembershipType::class, 'membership_type_id');
    }
}
