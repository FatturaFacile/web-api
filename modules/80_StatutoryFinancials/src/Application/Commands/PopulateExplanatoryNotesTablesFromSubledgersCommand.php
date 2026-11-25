<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Application\Commands;

/**
 * Populates the quantitative tables of the Nota Integrativa (IT) / Anhang (CH) from the subledgers: fixed assets (Mod 019), due dates
 * (Mod 017), leases (Mod 147), workforce (Mod 072) and the compensation accounts, with a fallback to the statements when a subledger
 * has no rows. Tables edited by hand and narrative sections edited by the user are kept.
 */
final readonly class PopulateExplanatoryNotesTablesFromSubledgersCommand
{
    public function __construct(public string $dossierId, public bool $overwriteNarrative = false, public ?string $userId = null) {}
}
