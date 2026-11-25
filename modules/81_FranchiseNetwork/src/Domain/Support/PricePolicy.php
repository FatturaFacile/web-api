<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Domain\Support;

use Modules\FranchiseNetwork\Domain\Enums\Jurisdiction;
use Modules\FranchiseNetwork\Domain\Enums\PriceType;

/**
 * Resale prices pushed to the stores. Under Swiss competition law (CO / LCart) the franchisor can only recommend prices, so a
 * `maximum` price becomes a non-binding recommendation for a Swiss store. In Italy a maximum price can be imposed, a recommended
 * price is flagged non-binding anyway.
 */
final class PricePolicy
{
    /**
     * @param  array<string, mixed>  $price  {price, price_type?}
     * @return array<string, mixed>
     */
    public static function forJurisdiction(array $price, Jurisdiction $jurisdiction): array
    {
        $type = PriceType::tryFrom((string) ($price['price_type'] ?? 'recommended')) ?? PriceType::Recommended;
        $downgraded = false;

        if ($jurisdiction === Jurisdiction::Ch && $type === PriceType::Maximum) {
            $type = PriceType::Recommended;
            $downgraded = true;
        }

        return [...$price, 'price_type' => $type->value, 'non_binding' => $type === PriceType::Recommended, 'downgraded_to_recommended' => $downgraded];
    }

    /**
     * Applies the policy to every price of a catalogue / price list payload.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function applyToPayload(array $payload, Jurisdiction $jurisdiction): array
    {
        foreach (['items', 'prices'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                $payload[$key] = array_map(
                    static fn (array $row): array => isset($row['price']) ? self::forJurisdiction($row, $jurisdiction) : $row,
                    array_values($payload[$key]),
                );
            }
        }

        return $payload;
    }
}
