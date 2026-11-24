<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Infrastructure\Adapters;

use Modules\CRM\Models\Opportunity;
use Modules\ResourcePlanning\Domain\Contracts\OpportunityPort;
use Modules\ResourcePlanning\Domain\ValueObjects\OpportunityRef;

/**
 * Reads the sales opportunities of the CRM (Mod 035). Only an opportunity with status open can hold a tentative booking.
 */
final class CrmOpportunityAdapter implements OpportunityPort
{
    public function opportunity(string $opportunityId): ?OpportunityRef
    {
        $opportunity = Opportunity::query()->find($opportunityId);

        return $opportunity === null ? null : new OpportunityRef((string) $opportunity->id, (string) $opportunity->name, $opportunity->status === 'open');
    }

    public function opportunityNames(array $opportunityIds): array
    {
        return $opportunityIds === [] ? [] : Opportunity::query()->whereIn('id', $opportunityIds)->pluck('name', 'id')->map(static fn ($name): string => (string) $name)->all();
    }
}
