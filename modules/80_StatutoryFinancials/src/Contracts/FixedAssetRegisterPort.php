<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Contracts;

use Carbon\CarbonImmutable;

/**
 * Query port of the fixed assets register (Mod 019 / cespiti): cost, revaluations, additions, disposals, depreciation funds and
 * net book value per asset category for a fiscal year.
 */
interface FixedAssetRegisterPort
{
    /**
     * @return array{available: bool, source: string, rows: list<array<string, mixed>>}
     */
    public function movements(string $tenantId, CarbonImmutable $from, CarbonImmutable $to): array;
}
