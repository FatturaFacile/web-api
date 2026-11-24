<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A company that sponsors the association.
 */
final class Sponsor extends AssociationRecord
{
    protected $table = 'assoc_sponsors';

    public function contracts(): HasMany
    {
        return $this->hasMany(SponsorContract::class, 'sponsor_id');
    }
}
