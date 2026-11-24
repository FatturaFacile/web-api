<?php

declare(strict_types=1);

namespace App\Domain\Procurement\Rfq\Contracts;

/**
 * Resolves a supplier's normalized scorecard rating (0.00 - 100.00) from Mod 043.
 */
interface SupplierRatingResolverInterface
{
    public function resolveRating(string $supplierId): float;

    /**
     * @param  list<string>  $supplierIds
     * @return array<string, float>
     */
    public function resolveRatings(array $supplierIds): array;
}
