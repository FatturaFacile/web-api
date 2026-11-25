<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Contracts;

use Carbon\CarbonImmutable;

/**
 * Query port of the workforce (Mod 072 / dipendenti): average headcount by category over a fiscal year.
 */
interface WorkforceRegisterPort
{
    /**
     * @return array{available: bool, source: string, rows: list<array{category: string, average_headcount: string, average_fte: string}>, total_headcount: string, total_fte: string}
     */
    public function averageHeadcount(string $tenantId, CarbonImmutable $from, CarbonImmutable $to): array;
}
