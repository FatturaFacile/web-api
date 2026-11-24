<?php

declare(strict_types=1);

namespace Modules\Rebates\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * A trade promotion valid in a date range, optionally limited to a product, a customer and a minimum quantity.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property string $discount_type
 * @property string $discount_value
 * @property string|null $product_id
 * @property string|null $contact_id
 * @property string|null $min_quantity
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property bool $is_active
 * @property string|null $notes
 */
final class RebatePromotion extends BaseEnterpriseModel
{
    public const string Percentage = 'percentage';

    public const string FixedAmount = 'fixed_amount';

    /** @var list<string> */
    public const array DiscountTypes = [self::Percentage, self::FixedAmount];

    protected $table = 'rebate_promotions';

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'discount_value' => 'decimal:4',
            'min_quantity' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The state of the promotion on a day: inactive, scheduled, active or expired.
     */
    public function stateOn(Carbon $day): string
    {
        return match (true) {
            ! $this->is_active => 'inactive',
            $day->lt($this->starts_on) => 'scheduled',
            $day->gt($this->ends_on) => 'expired',
            default => 'active',
        };
    }
}
