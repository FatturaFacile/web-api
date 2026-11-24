<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

use Modules\Associations\Enums\FeeFrequency;

/**
 * A price list entry: amount per period, or for the whole package of lessons when lessons_included is set.
 */
final class FeePlan extends AssociationRecord
{
    protected $table = 'assoc_fee_plans';

    protected function casts(): array
    {
        return [
            'frequency' => FeeFrequency::class,
            'amount' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'lessons_included' => 'integer',
            'instalments' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
