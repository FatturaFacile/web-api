<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Contracts;

use Carbon\CarbonImmutable;

/**
 * Rates fixed by the controller of a group for a period (closing, average, historical), which win over the published
 * exchange rates when an entity is translated. Rates are group-currency units for one unit of the entity currency.
 */
interface ConsolidationRateOverrides
{
    /**
     * @return array{closing?: string, average?: string, historical?: string} only the rates that were fixed
     */
    public function overridesFor(string $currency, string $groupCurrency, CarbonImmutable $periodEnd): array;
}
