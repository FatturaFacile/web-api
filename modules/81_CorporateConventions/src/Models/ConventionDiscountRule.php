<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\CorporateConventions\Domain\Enums\RuleType;
use Modules\CorporateConventions\Domain\ValueObjects\DiscountRuleSpec;

/**
 * A row of the discount matrix of a convention.
 *
 * @property string $convention_id
 * @property int $priority
 * @property RuleType $rule_type
 * @property string|null $target_price_list_id
 * @property string|null $target_category_id
 * @property string $discount_percentage
 * @property bool $exclude_promotional_items
 * @property bool $cumulative_with_other_discounts
 * @property string|null $min_cart_amount
 * @property string|null $created_by
 * @property string|null $updated_by
 * @property string|null $deleted_by
 */
final class ConventionDiscountRule extends BaseEnterpriseModel
{
    protected $table = 'sales_convention_discount_rules';

    protected function casts(): array
    {
        return [
            'rule_type' => RuleType::class,
            'priority' => 'integer',
            'discount_percentage' => 'decimal:4',
            'exclude_promotional_items' => 'boolean',
            'cumulative_with_other_discounts' => 'boolean',
            'min_cart_amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<CorporatePartnerConvention, $this>
     */
    public function convention(): BelongsTo
    {
        return $this->belongsTo(CorporatePartnerConvention::class, 'convention_id');
    }

    public function toSpec(): DiscountRuleSpec
    {
        return new DiscountRuleSpec(
            $this->id,
            $this->priority,
            $this->rule_type,
            (string) $this->discount_percentage,
            $this->exclude_promotional_items,
            $this->cumulative_with_other_discounts,
            $this->target_price_list_id,
            $this->target_category_id,
            $this->min_cart_amount === null ? null : (string) $this->min_cart_amount,
        );
    }
}
