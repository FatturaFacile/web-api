<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Infrastructure\Adapters;

use Carbon\CarbonImmutable;
use Modules\HumanResources\Models\TimePublicHoliday;
use Modules\HumanResources\Services\TimeTracking\SwissHolidayCalendar;
use Modules\Manufacturing\Support\ItalianHolidayCalendar;
use Modules\ResourcePlanning\Domain\Contracts\HolidayPort;
use Modules\ResourcePlanning\Domain\Services\MunicipalPatronSaints;
use Modules\ResourcePlanning\Domain\ValueObjects\HolidayDay;
use Modules\ResourcePlanning\Infrastructure\Models\LocalHoliday;

/**
 * The holiday calendar of a working location, assembled from the calendars the platform already keeps:
 *  - Switzerland: the national and cantonal holidays of the time tracking calendar (Mod 073), overridden by the
 *    time_public_holidays rows of the tenant (half days included);
 *  - Italy: the national holidays and the patron saint of the provincial capital (Mod 072), plus the patron saint of the
 *    municipality when it is known (MunicipalPatronSaints);
 *  - every country: the local holidays the tenant records (rp_local_holidays), for a municipality or a region.
 */
final class CompositeHolidayAdapter implements HolidayPort
{
    public function holidays(string $country, ?string $region, ?string $municipality, int $year): array
    {
        $country = strtoupper($country);
        $days = match ($country) {
            'CH' => $this->swiss($region, $year),
            'IT' => $this->italian($region, $municipality, $year),
            default => [],
        };

        foreach ($this->local($country, $region, $municipality, $year) as $date => $holiday) {
            $days[$date] = $holiday;
        }

        ksort($days);

        return $days;
    }

    /**
     * @return array<string, HolidayDay>
     */
    private function swiss(?string $canton, int $year): array
    {
        $code = $canton === null || $canton === '' ? SwissHolidayCalendar::DEFAULT_CANTON : strtoupper($canton);
        $days = [];

        foreach (SwissHolidayCalendar::forCanton($code, $year) as $entry) {
            $days[$entry->date] = new HolidayDay($entry->name);
        }

        $overrides = TimePublicHoliday::query()
            ->whereDate('holiday_date', '>=', sprintf('%04d-01-01', $year))
            ->whereDate('holiday_date', '<=', sprintf('%04d-12-31', $year))
            ->where(static fn ($query) => $query->whereNull('canton')->orWhere('canton', $code))
            ->get()
            ->sortBy(static fn (TimePublicHoliday $holiday): array => [$holiday->tenant_id !== null, $holiday->canton !== null]);

        foreach ($overrides as $holiday) {
            $days[$holiday->holiday_date->format('Y-m-d')] = new HolidayDay($holiday->name, (float) $holiday->day_fraction);
        }

        return $days;
    }

    /**
     * @return array<string, HolidayDay>
     */
    private function italian(?string $province, ?string $municipality, int $year): array
    {
        $days = [];

        foreach (ItalianHolidayCalendar::forRegion($province, $year) as $date => $name) {
            $days[$date] = new HolidayDay($name);
        }

        $saint = MunicipalPatronSaints::for($municipality);

        if ($saint !== null) {
            $date = CarbonImmutable::create($year, $saint['month'], $saint['day'])->format('Y-m-d');
            $days[$date] = new HolidayDay($saint['name']);
        }

        return $days;
    }

    /**
     * The tenant's local holidays (and the generic rows without a tenant) that apply to the country, the region and the
     * municipality of the location. A row without a region applies to the whole country; one without a municipality to the
     * whole region.
     *
     * @return array<string, HolidayDay>
     */
    private function local(string $country, ?string $region, ?string $municipality, int $year): array
    {
        $days = [];
        $rows = LocalHoliday::query()
            ->where('country', $country)
            ->where(static fn ($query) => $query->whereNull('year')->orWhere('year', $year))
            ->get();

        foreach ($rows as $row) {
            $regionMatches = $row->region_code === null || ($region !== null && strcasecmp($row->region_code, $region) === 0);
            $municipalityMatches = $row->municipality === null || ($municipality !== null && strcasecmp($row->municipality, $municipality) === 0);

            if ($regionMatches && $municipalityMatches && checkdate($row->month, $row->day, $year)) {
                $days[sprintf('%04d-%02d-%02d', $year, $row->month, $row->day)] = new HolidayDay($row->name, (float) $row->day_fraction);
            }
        }

        return $days;
    }
}
