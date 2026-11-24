<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * People of the same household, for the family discounts.
 */
final class Family extends AssociationRecord
{
    protected $table = 'assoc_families';

    public function members(): HasMany
    {
        return $this->hasMany(Member::class, 'family_id');
    }
}
