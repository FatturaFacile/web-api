<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Contracts;

use Carbon\CarbonImmutable;

/**
 * Query port of the compensation of directors and statutory auditors, read from the ledger accounts configured for them.
 */
interface CompensationRegisterPort
{
    /**
     * @return array{available: bool, source: string, rows: list<array{role: string, fees: string, advances: string, guarantees: string}>}
     */
    public function compensation(string $tenantId, string $family, CarbonImmutable $from, CarbonImmutable $to): array;
}
