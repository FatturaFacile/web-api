<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Application\Services;

use Carbon\CarbonImmutable;
use Modules\ResourcePlanning\Domain\Contracts\HolidayPort;
use Modules\ResourcePlanning\Domain\Exceptions\ResourceNotFoundException;
use Modules\ResourcePlanning\Domain\ValueObjects\HolidayDay;
use Modules\ResourcePlanning\Infrastructure\Models\LocalHoliday;
use Modules\ResourcePlanning\Infrastructure\Models\UtilizationTarget;

/**
 * The tenant settings of the planner: billable utilization targets and local holidays (municipal patron saints and the like).
 */
final class PlanningSettingsService
{
    public function __construct(private readonly HolidayPort $holidays) {}

    /**
     * @return list<UtilizationTarget>
     */
    public function targets(): array
    {
        return UtilizationTarget::query()->orderBy('department_id')->orderBy('cost_center_id')->get()->all();
    }

    /**
     * Sets the target of a department, a cost centre or (both null) the tenant.
     */
    public function setTarget(?string $departmentId, ?string $costCenterId, float $targetPercent): UtilizationTarget
    {
        $target = UtilizationTarget::query()
            ->where('department_id', $departmentId)
            ->where('cost_center_id', $costCenterId)
            ->first();

        if ($target === null) {
            return UtilizationTarget::query()->create(['department_id' => $departmentId, 'cost_center_id' => $costCenterId, 'target_percent' => $targetPercent]);
        }

        $target->update(['target_percent' => $targetPercent]);

        return $target->refresh();
    }

    /**
     * @return list<LocalHoliday>
     */
    public function localHolidays(): array
    {
        return LocalHoliday::query()->orderBy('country')->orderBy('region_code')->orderBy('month')->orderBy('day')->get()->all();
    }

    /**
     * @param  array{country: string, region_code?: string|null, municipality?: string|null, month: int, day: int, year?: int|null, name: string, day_fraction?: float|null}  $data
     */
    public function addLocalHoliday(array $data): LocalHoliday
    {
        return LocalHoliday::query()->create([
            'country' => strtoupper($data['country']),
            'region_code' => isset($data['region_code']) ? strtoupper($data['region_code']) : null,
            'municipality' => $data['municipality'] ?? null,
            'month' => $data['month'],
            'day' => $data['day'],
            'year' => $data['year'] ?? null,
            'name' => $data['name'],
            'day_fraction' => $data['day_fraction'] ?? 1.0,
        ]);
    }

    public function removeLocalHoliday(string $id): void
    {
        $holiday = LocalHoliday::query()->find($id) ?? throw new ResourceNotFoundException('local_holiday_not_found', 'The holiday does not exist.');

        if ($holiday->tenant_id === null) {
            throw new ResourceNotFoundException('local_holiday_not_found', 'A generic holiday cannot be removed by a tenant.');
        }

        $holiday->delete();
    }

    /**
     * The holidays of a working location in a year, ordered by date.
     *
     * @return list<array{date: string, name: string, day_fraction: float}>
     */
    public function resolvedHolidays(string $country, ?string $region, ?string $municipality, int $year): array
    {
        $days = $this->holidays->holidays($country, $region, $municipality, $year);

        return array_map(static fn (string $date, HolidayDay $day): array => ['date' => $date, 'name' => $day->name, 'day_fraction' => $day->fraction], array_keys($days), $days);
    }

    public function resolvedYear(?int $year): int
    {
        return $year ?? CarbonImmutable::today()->year;
    }
}
