<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Associations\Enums\YearKind;

/**
 * An associative year or a sport season.
 */
final class AssociativeYear extends AssociationRecord
{
    protected $table = 'assoc_years';

    protected function casts(): array
    {
        return ['kind' => YearKind::class, 'starts_on' => 'date', 'ends_on' => 'date'];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class, 'year_id');
    }
}
