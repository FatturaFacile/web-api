<?php

declare(strict_types=1);

namespace App\Services\ForeignTrade;

use App\DTOs\ForeignTrade\CustomsValue;
use App\Enums\Incoterm;

/**
 * Customs value at the importing border: the invoice price plus the carriage and insurance up to the border that the
 * Incoterm leaves outside the price. The statistical value is taken on the same CIF basis.
 */
final class CustomsValueCalculator
{
    public function calculate(Incoterm $incoterm, float $goodsValue, float $freight = 0.0, float $insurance = 0.0): CustomsValue
    {
        $freightAdded = $incoterm->sellerPaysFreight() ? 0.0 : max($freight, 0.0);
        $insuranceAdded = $incoterm->sellerPaysInsurance() ? 0.0 : max($insurance, 0.0);
        $customs = round($goodsValue + $freightAdded + $insuranceAdded, 2);

        return new CustomsValue(
            incoterm: $incoterm->value,
            goodsValue: round($goodsValue, 2),
            freightAdded: round($freightAdded, 2),
            insuranceAdded: round($insuranceAdded, 2),
            customsValue: $customs,
            statisticalValue: $customs,
        );
    }
}
