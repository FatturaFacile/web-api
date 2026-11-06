<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

/**
 * A discount from the n-th person of a family on the same fee plan.
 */
final class FamilyDiscount extends AssociationRecord
{
    protected $table = 'assoc_family_discounts';

    protected function casts(): array
    {
        return ['percentage' => 'decimal:2', 'from_position' => 'integer', 'is_active' => 'boolean'];
    }
}
