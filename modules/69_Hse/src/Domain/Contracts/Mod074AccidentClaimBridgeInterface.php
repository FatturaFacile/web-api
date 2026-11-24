<?php

declare(strict_types=1);

namespace Modules\Hse\Domain\Contracts;

use Modules\Hse\Infrastructure\Models\AccidentClaimDraft;
use Modules\Hse\Infrastructure\Models\WorkplaceIncidentReport;

/**
 * Anti-corruption adapter towards Payroll, Social Security and Statutory Declarations (Mod 074): translates an incident
 * into the SUVA Sunet (CH) or INAIL (IT) payload and registers the pre-filled declaration draft.
 */
interface Mod074AccidentClaimBridgeInterface
{
    /**
     * @return array<string, mixed>
     */
    public function buildPayload(WorkplaceIncidentReport $incident): array;

    public function createDraft(WorkplaceIncidentReport $incident): AccidentClaimDraft;
}
