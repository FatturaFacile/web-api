<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Domain\ValueObjects;

use Modules\CorporateConventions\Domain\Enums\RuleType;

/**
 * A row of the discount matrix of a convention, free of persistence.
 */
final readonly class DiscountRuleSpec
{
    public function __construct(
        public string $id,
        public int $priority,
        public RuleType $type,
        public string $discountPercentage,
        public bool $excludePromotionalItems,
        public bool $cumulativeWithOtherDiscounts,
        public ?string $targetPriceListId = null,
        public ?string $targetCategoryId = null,
        public ?string $minCartAmount = null,
    ) {}

    /**
     * Whether the rule concerns the line (its target); the cart condition is checked by the evaluator.
     */
    public function targets(CartLine $line): bool
    {
        if ($this->excludePromotionalItems && $line->isPromotional) {
            return false;
        }

        return match ($this->type) {
            RuleType::GlobalPercentage => true,
            RuleType::CategoryPercentage => $this->targetCategoryId !== null && $line->categoryId === $this->targetCategoryId,
            RuleType::ProductFamilyPercentage => $this->targetCategoryId !== null && in_array($this->targetCategoryId, $line->categoryPath, true),
            RuleType::PriceList => $this->targetPriceListId !== null && isset($line->priceListUnitPrices[$this->targetPriceListId]),
        };
    }
}
