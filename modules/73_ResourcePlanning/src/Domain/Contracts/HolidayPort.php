<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Domain\Contracts;

use Modules\ResourcePlanning\Domain\ValueObjects\HolidayDay;

/**
 * Public holidays of a working location (Mod 072/073): the national ones, the Swiss cantonal ones, the Italian regional
 * ones and the patron saint of a municipality, plus what the tenant adds.
 */
interface HolidayPort
{
    /**
     * @return array<string, HolidayDay> keyed by Y-m-d
     */
    public function holidays(string $country, ?string $region, ?string $municipality, int $year): array;
}
