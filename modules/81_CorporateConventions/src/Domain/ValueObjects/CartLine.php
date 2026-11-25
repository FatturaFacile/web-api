<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Domain\ValueObjects;

/**
 * One line of the cart a convention is applied to. Amounts are decimal strings.
 */
final readonly class CartLine
{
    /**
     * @param  list<string>  $categoryPath  the category of the product followed by its ancestors (the "product family" chain)
     * @param  array<string, string>  $priceListUnitPrices  unit price of the product by price list id
     */
    public function __construct(
        public string $lineId,
        public string $quantity,
        public string $unitPrice,
        public string $unitCost = '0',
        public ?string $categoryId = null,
        public array $categoryPath = [],
        public bool $isPromotional = false,
        public string $existingDiscountPercent = '0',
        public array $priceListUnitPrices = [],
    ) {}
}
