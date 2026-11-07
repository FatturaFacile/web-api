<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An amount of the 5x1000 received by the association, and the expenses it paid for.
 *
 * @property string $id
 * @property string $amount
 */
final class FivePerMilleAllocation extends AssociationRecord
{
    protected $table = 'assoc_five_per_mille_allocations';

    protected function casts(): array
    {
        return ['received_on' => 'date', 'amount' => 'decimal:2'];
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(FivePerMilleExpense::class, 'allocation_id');
    }
}
