<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Contracts;

use Carbon\CarbonImmutable;

/**
 * Query port of the due-date schedule (Mod 017 / scadenzario): receivables and payables open at a date, by maturity.
 */
interface DueDateSchedulePort
{
    /**
     * @return array{available: bool, source: string, receivables: array<string, string>, payables: array<string, string>, payables_due_6m: string}
     */
    public function maturities(string $tenantId, CarbonImmutable $asOf): array;
}
