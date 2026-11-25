<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services\Consolidation;

use Carbon\CarbonImmutable;
use Modules\GeneralLedger\Contracts\ConsolidationRateOverrides;

/**
 * Default binding: the translation uses the published exchange rates only.
 */
final readonly class NoRateOverrides implements ConsolidationRateOverrides
{
    public function overridesFor(string $currency, string $groupCurrency, CarbonImmutable $periodEnd): array
    {
        return [];
    }
}
