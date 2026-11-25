<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Contracts;

use Carbon\CarbonImmutable;

/**
 * Query port of the lease register (Mod 147 / leasing): commitments, redemption values and finance charges.
 */
interface LeaseRegisterPort
{
    /**
     * @return array{available: bool, source: string, rows: list<array<string, mixed>>, due_6m: string}
     */
    public function commitments(string $tenantId, CarbonImmutable $from, CarbonImmutable $to): array;
}
