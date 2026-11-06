<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person holds a registry type for a period, optionally tied to a year: the rows are the history per year.
 */
final class RegistryAssignment extends AssociationRecord
{
    protected $table = 'assoc_registry_assignments';

    protected function casts(): array
    {
        return ['valid_from' => 'date', 'valid_to' => 'date'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    public function registryType(): BelongsTo
    {
        return $this->belongsTo(RegistryType::class, 'registry_type_id');
    }

    public function year(): BelongsTo
    {
        return $this->belongsTo(AssociativeYear::class, 'year_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeValidOn(Builder $query, string $day): Builder
    {
        return $query->whereDate('valid_from', '<=', $day)
            ->where(static fn (Builder $inner) => $inner->whereNull('valid_to')->orWhereDate('valid_to', '>=', $day));
    }
}
