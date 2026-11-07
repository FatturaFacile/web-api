<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

use App\Models\Contact;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Links a minor to the contact of a guardian, who does not have to be a member.
 */
final class Guardianship extends AssociationRecord
{
    protected $table = 'assoc_guardianships';

    protected function casts(): array
    {
        return ['is_primary' => 'boolean', 'valid_from' => 'date', 'valid_to' => 'date'];
    }

    public function minor(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'minor_member_id');
    }

    public function guardian(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'guardian_contact_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        $today = CarbonImmutable::today()->toDateString();

        return $query->where(static fn (Builder $inner) => $inner->whereNull('valid_from')->orWhereDate('valid_from', '<=', $today))
            ->where(static fn (Builder $inner) => $inner->whereNull('valid_to')->orWhereDate('valid_to', '>=', $today));
    }
}
