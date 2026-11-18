<?php

declare(strict_types=1);

namespace Modules\Procurement\Contracts;

/**
 * What other modules (Inventory) may ask of Procurement about purchase suggestions, without importing its models.
 */
interface PurchaseSuggestionHandOffInterface
{
    /**
     * Generates the missing purchase suggestions of the tenant from its reorder rules and returns the new ones.
     *
     * @return list<array{id: string, source: string, source_id: string, quantity: string, supplier_id: string|null, status: string}>
     */
    public function handOff(string $tenantId): array;
}
