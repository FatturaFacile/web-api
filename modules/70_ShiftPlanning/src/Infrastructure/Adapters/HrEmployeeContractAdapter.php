<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Infrastructure\Adapters;

use Carbon\CarbonImmutable;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\EmployeeSkill;
use Modules\HumanResources\Models\EmploymentContract;
use Modules\ShiftPlanning\Domain\Contracts\EmployeeContractPort;
use Modules\ShiftPlanning\Domain\ValueObjects\EmployeeContractProfile;

/**
 * Reads contracts, hourly cost rates and skills from the HR module (Mod 068). The skill ids of an employee include the
 * position, so a shift can require either a skill or a role.
 */
final class HrEmployeeContractAdapter implements EmployeeContractPort
{
    public function profilesFor(array $employeeIds, CarbonImmutable $date): array
    {
        if ($employeeIds === []) {
            return [];
        }

        $day = $date->format('Y-m-d');
        $employees = Employee::query()->whereIn('id', $employeeIds)->get()->keyBy('id');
        $contracts = EmploymentContract::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('is_active', true)
            ->whereDate('start_date', '<=', $day)
            ->where(static fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $day))
            ->orderBy('start_date')
            ->get()
            ->keyBy('employee_id');
        $skills = EmployeeSkill::query()->whereIn('employee_id', $employeeIds)->get()->groupBy('employee_id');

        $profiles = [];

        foreach ($contracts as $employeeId => $contract) {
            $employee = $employees->get($employeeId);

            if (! $employee instanceof Employee) {
                continue;
            }

            $cost = (float) $contract->loaded_hourly_cost > 0 ? (float) $contract->loaded_hourly_cost
                : ((float) $contract->hourly_rate > 0 ? (float) $contract->hourly_rate : (float) $employee->hourly_cost_rate);

            $skillIds = $skills->get($employeeId, collect())->pluck('skill_id')->map(static fn ($id): string => (string) $id)->all();

            if ($employee->position_id !== null) {
                $skillIds[] = (string) $employee->position_id;
            }

            $profiles[(string) $employeeId] = new EmployeeContractProfile(
                (string) $employeeId,
                (string) $employee->full_name,
                (float) $contract->weekly_hours,
                $cost,
                array_values(array_unique($skillIds)),
                (bool) (($contract->terms['regular_night_work'] ?? false)),
            );
        }

        return $profiles;
    }

    public function employeesWithSkill(?string $skillId, CarbonImmutable $date): array
    {
        $day = $date->format('Y-m-d');
        $active = EmploymentContract::query()
            ->where('is_active', true)
            ->whereDate('start_date', '<=', $day)
            ->where(static fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $day))
            ->pluck('employee_id');

        if ($skillId === null) {
            return $active->map(static fn ($id): string => (string) $id)->unique()->values()->all();
        }

        $withSkill = EmployeeSkill::query()->where('skill_id', $skillId)->pluck('employee_id')
            ->merge(Employee::query()->where('position_id', $skillId)->pluck('id'));

        return $active->intersect($withSkill)->map(static fn ($id): string => (string) $id)->unique()->values()->all();
    }
}
