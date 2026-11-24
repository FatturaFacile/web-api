<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Domain\Contracts;

use Modules\ResourcePlanning\Domain\ValueObjects\OpportunityRef;

/**
 * Sales opportunities (Mod 035).
 */
interface OpportunityPort
{
    public function opportunity(string $opportunityId): ?OpportunityRef;

    /**
     * @param  list<string>  $opportunityIds
     * @return array<string, string> opportunity name by id
     */
    public function opportunityNames(array $opportunityIds): array;
}
