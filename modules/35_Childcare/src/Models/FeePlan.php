<?php

declare(strict_types=1);

namespace Modules\Childcare\Models;

/**
 * A fee plan: a flat monthly fee or a fee per attended day, plus a price per meal. Amounts and tax rate are entered by
 * the kindergarten.
 *
 * @property string $name
 * @property string $basis
 * @property string $amount
 * @property string $meal_amount
 * @property string $tax_rate
 * @property string $currency
 */
final class FeePlan extends ChildcareRecord
{
    /** @var list<string> */
    public const array Bases = ['monthly_flat', 'per_day'];

    protected $table = 'cc_fee_plans';

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'meal_amount' => 'decimal:2', 'tax_rate' => 'decimal:2'];
    }
}
