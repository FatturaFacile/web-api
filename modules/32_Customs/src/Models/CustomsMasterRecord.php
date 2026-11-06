<?php

declare(strict_types=1);

namespace Modules\Customs\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * A row of a customs code table. A company row overrides the generic row with the same code.
 */
abstract class CustomsMasterRecord extends CustomsRecord
{
    protected function casts(): array
    {
        return ['valid_from' => 'date', 'valid_to' => 'date', 'is_active' => 'boolean'];
    }

    /**
     * Whether the code can be used on the day: active, and within its validity when it has one.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeValidOn(Builder $query, string|Carbon $date): Builder
    {
        return $query->where('is_active', true)
            ->where(fn (Builder $inner) => $inner->whereNull('valid_from')->orWhereDate('valid_from', '<=', $date))
            ->where(fn (Builder $inner) => $inner->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date));
    }
}
