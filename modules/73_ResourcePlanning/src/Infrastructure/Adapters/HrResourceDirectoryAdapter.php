<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Infrastructure\Adapters;

use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Modules\HumanResources\Models\CostCenter;
use Modules\HumanResources\Models\Department;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\EmploymentContract;
use Modules\HumanResources\Models\TimeRecordingProfile;
use Modules\ResourcePlanning\Domain\Contracts\ResourceDirectoryPort;
use Modules\ResourcePlanning\Domain\ValueObjects\ResourceProfile;

/**
 * Reads employees, contracts, FTE and working locations from the HR module (Mod 068).
 *
 * The contractual week is the weekly_hours of the contract. A contract that still states a full-time week (40 hours or more)
 * but an FTE below 100 % is scaled by the FTE; a part-time contract that already states its own hours is taken as it is.
 * The weekdays worked come from terms.work_weekdays (ISO numbers) of the contract, else from the work_days_per_week of the
 * time recording profile (the first days of the week), else Monday to Friday.
 *
 * The working location (country, region, municipality) comes from metadata.work_location of the employee, else from the
 * canton of the time recording profile, else from the country of the tenant with the default canton for Switzerland.
 */
final class HrResourceDirectoryAdapter implements ResourceDirectoryPort
{
    private const float FullTimeWeek = 40.0;

    public function __construct(private readonly TenantContext $tenantContext) {}

    public function resources(?array $employeeIds, ?string $departmentId, CarbonImmutable $date): array
    {
        if ($employeeIds === []) {
            return [];
        }

        $day = $date->format('Y-m-d');
        $contracts = EmploymentContract::query()
            ->when($employeeIds !== null, static fn ($query) => $query->whereIn('employee_id', $employeeIds ?? []))
            ->where('is_active', true)
            ->whereDate('start_date', '<=', $day)
            ->where(static fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $day))
            ->orderBy('start_date')
            ->get()
            ->keyBy('employee_id');

        if ($contracts->isEmpty()) {
            return [];
        }

        $employees = Employee::query()
            ->whereIn('id', $contracts->keys()->all())
            ->when($departmentId !== null, static fn ($query) => $query->where('department_id', $departmentId))
            ->whereNotIn('employment_status', ['terminated', 'retired', 'candidate'])
            ->orderBy('full_name')
            ->get();

        $departments = Department::query()->whereIn('id', $employees->pluck('department_id')->filter()->unique()->all())->pluck('name', 'id');
        $costCenters = CostCenter::query()->whereIn('id', $employees->pluck('cost_center_id')->filter()->unique()->all())->pluck('name', 'id');
        $timeProfiles = TimeRecordingProfile::query()->whereIn('user_id', $employees->pluck('user_id')->filter()->unique()->all())->get()->keyBy('user_id');
        $tenantCountry = strtoupper((string) ($this->tenantContext->getTenant()->country ?? 'IT'));

        $profiles = [];

        foreach ($employees as $employee) {
            $contract = $contracts->get($employee->id);

            if (! $contract instanceof EmploymentContract) {
                continue;
            }

            $timeProfile = $employee->user_id === null ? null : $timeProfiles->get($employee->user_id);
            $fte = max(0.0, (float) $contract->fte_percentage) / 100;
            $weekly = (float) $contract->weekly_hours;

            if ($weekly >= self::FullTimeWeek && $fte > 0.0 && $fte < 1.0) {
                $weekly *= $fte;
            }

            [$country, $region, $municipality] = $this->location($employee, $timeProfile, $tenantCountry);
            $cost = (float) $contract->loaded_hourly_cost > 0 ? (float) $contract->loaded_hourly_cost
                : ((float) $contract->hourly_rate > 0 ? (float) $contract->hourly_rate : (float) $employee->hourly_cost_rate);

            $profiles[] = new ResourceProfile(
                (string) $employee->id,
                (string) $employee->full_name,
                $weekly,
                $this->weekdays($contract, $timeProfile),
                $fte > 0.0 ? $fte : 1.0,
                $cost,
                $employee->department_id === null ? null : (string) $employee->department_id,
                $employee->department_id === null ? null : ($departments->get($employee->department_id) === null ? null : (string) $departments->get($employee->department_id)),
                $employee->cost_center_id === null ? null : (string) $employee->cost_center_id,
                $employee->cost_center_id === null ? null : ($costCenters->get($employee->cost_center_id) === null ? null : (string) $costCenters->get($employee->cost_center_id)),
                $country,
                $region,
                $municipality,
            );
        }

        return $profiles;
    }

    public function names(array $employeeIds): array
    {
        if ($employeeIds === []) {
            return [];
        }

        return Employee::query()->whereIn('id', $employeeIds)->pluck('full_name', 'id')->map(static fn ($name): string => (string) $name)->all();
    }

    /**
     * @return list<int>
     */
    private function weekdays(EmploymentContract $contract, ?TimeRecordingProfile $timeProfile): array
    {
        $terms = is_array($contract->terms) ? $contract->terms : [];
        $configured = $terms['work_weekdays'] ?? null;

        if (is_array($configured) && $configured !== []) {
            return array_values(array_unique(array_filter(array_map('intval', $configured), static fn (int $day): bool => $day >= 1 && $day <= 7)));
        }

        $days = $timeProfile === null ? 5 : max(1, min(7, (int) $timeProfile->work_days_per_week));

        return range(1, $days);
    }

    /**
     * @return array{0: string, 1: string|null, 2: string|null}
     */
    private function location(Employee $employee, ?TimeRecordingProfile $timeProfile, string $tenantCountry): array
    {
        $metadata = is_array($employee->metadata) ? $employee->metadata : [];
        $location = is_array($metadata['work_location'] ?? null) ? $metadata['work_location'] : [];

        if (isset($location['country']) && is_string($location['country'])) {
            return [
                strtoupper($location['country']),
                isset($location['region']) && is_string($location['region']) ? strtoupper($location['region']) : null,
                isset($location['municipality']) && is_string($location['municipality']) ? $location['municipality'] : null,
            ];
        }

        if ($timeProfile !== null && $timeProfile->canton !== null && $timeProfile->canton !== '') {
            return ['CH', strtoupper((string) $timeProfile->canton), null];
        }

        return $tenantCountry === 'CH' ? ['CH', (string) config('resource_planning.default_swiss_canton', 'TI'), null] : [$tenantCountry, null, null];
    }
}
