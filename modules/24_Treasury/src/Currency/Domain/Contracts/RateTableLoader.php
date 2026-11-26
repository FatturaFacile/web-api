<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Domain\Contracts;

use Modules\Treasury\Currency\Domain\Entities\ExchangeRateTable;
use Modules\Treasury\Currency\Domain\Enums\RateType;

/**
 * Loads the rates a company sees (official and its own) between two days, for the pairs among some currencies.
 */
interface RateTableLoader
{
    /**
     * @param  list<string>  $currencies  ISO codes: only pairs with both currencies in the list are loaded
     * @param  list<RateType>  $types
     */
    public function load(array $currencies, string $from, string $to, ?string $tenantId, array $types): ExchangeRateTable;
}
