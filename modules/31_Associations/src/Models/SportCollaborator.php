<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A person of the registry who works in sports for the association (coach, instructor, referee, athlete...), with the
 * insurance coverage to watch.
 *
 * @property string $id
 * @property string $member_id
 * @property-read Member $member
 */
final class SportCollaborator extends AssociationRecord
{
    protected $table = 'assoc_sport_collaborators';

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'insurance_expires_on' => 'date'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(SportContract::class, 'collaborator_id');
    }

    public function compensations(): HasMany
    {
        return $this->hasMany(SportCompensation::class, 'collaborator_id');
    }
}
