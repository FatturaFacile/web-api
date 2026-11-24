<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Agent tools that are either personal (owner_user_id) or shared with every agent of the tenant (is_shared).
 */
trait SharedOrPersonal
{
    /**
     * Shared rows and the rows of the given user.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisibleTo(Builder $query, string $userId): Builder
    {
        return $query->where(fn (Builder $visible) => $visible->where('is_shared', true)->orWhere('owner_user_id', $userId));
    }
}
