<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A period of volunteering of a person of the registry, with its activity and insurance coverage. Occasional
 * volunteers are kept apart from the register of those who volunteer on a regular basis.
 *
 * @property string $id
 * @property string $member_id
 * @property bool $is_occasional
 * @property-read Member $member
 */
final class Volunteer extends AssociationRecord
{
    protected $table = 'assoc_volunteers';

    protected function casts(): array
    {
        return ['started_on' => 'date', 'ended_on' => 'date', 'insurance_expires_on' => 'date', 'is_occasional' => 'boolean'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    public function trainings(): HasMany
    {
        return $this->hasMany(VolunteerTraining::class, 'volunteer_id');
    }

    /**
     * Periods that overlap a year.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActiveInYear(Builder $query, int $year): Builder
    {
        $start = CarbonImmutable::create($year)->startOfYear()->toDateString();
        $end = CarbonImmutable::create($year)->endOfYear()->toDateString();

        return $query->whereDate('started_on', '<=', $end)->where(static fn (Builder $inner) => $inner->whereNull('ended_on')->orWhereDate('ended_on', '>=', $start));
    }
}
