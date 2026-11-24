<?php

declare(strict_types=1);

namespace Modules\TalentLearning\Services;

use Illuminate\Database\Eloquent\Builder;
use Modules\HumanResources\Models\Department;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\Position;
use Modules\TalentLearning\Contracts\HrDirectory;
use Modules\TalentLearning\Support\DirectoryEmployee;
use Modules\TalentLearning\Support\DirectoryPosition;

/**
 * HR directory backed by the human resources models.
 */
final class EloquentHrDirectory implements HrDirectory
{
    /** @var list<string> */
    private const array InactiveStatuses = ['candidate', 'terminated', 'offboarding', 'retired'];

    public function employee(string $tenantId, string $employeeId): ?DirectoryEmployee
    {
        $employee = $this->employees($tenantId)->whereKey($employeeId)->first();

        return $employee instanceof Employee ? $this->toEmployee($employee) : null;
    }

    public function employeeOfUser(string $tenantId, string $userId): ?DirectoryEmployee
    {
        $employee = $this->employees($tenantId)->where('user_id', $userId)->first();

        return $employee instanceof Employee ? $this->toEmployee($employee) : null;
    }

    public function employeesByIds(string $tenantId, array $employeeIds): array
    {
        if ($employeeIds === []) {
            return [];
        }

        $found = [];

        foreach ($this->employees($tenantId)->whereIn('id', array_values(array_unique($employeeIds)))->get() as $employee) {
            $found[(string) $employee->id] = $this->toEmployee($employee);
        }

        return $found;
    }

    public function activeEmployees(string $tenantId, ?string $search = null, ?int $limit = null): array
    {
        $query = $this->employees($tenantId)
            ->whereNotIn('employment_status', self::InactiveStatuses)
            ->when($search !== null && trim($search) !== '', static function (Builder $query) use ($search): void {
                $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], mb_strtolower(trim((string) $search))).'%';
                $query->where(static fn (Builder $inner) => $inner->whereRaw('lower(full_name) like ?', [$term])->orWhereRaw('lower(employee_number) like ?', [$term]));
            })
            ->orderBy('full_name');

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get()->map(fn (Employee $employee): DirectoryEmployee => $this->toEmployee($employee))->values()->all();
    }

    public function position(string $tenantId, string $positionId): ?DirectoryPosition
    {
        $position = Position::query()->with('department:id,name')->where('tenant_id', $tenantId)->whereKey($positionId)->first();

        return $position instanceof Position ? $this->toPosition($position) : null;
    }

    public function positions(string $tenantId, ?string $search = null, int $limit = 25): array
    {
        return Position::query()
            ->with('department:id,name')
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->when($search !== null && trim($search) !== '', static function (Builder $query) use ($search): void {
                $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], mb_strtolower(trim((string) $search))).'%';
                $query->where(static fn (Builder $inner) => $inner->whereRaw('lower(title) like ?', [$term])->orWhereRaw('lower(code) like ?', [$term]));
            })
            ->orderBy('title')
            ->limit($limit)
            ->get()
            ->map(fn (Position $position): DirectoryPosition => $this->toPosition($position))
            ->values()
            ->all();
    }

    public function baseAnnualSalary(string $tenantId, string $employeeId): ?array
    {
        $employee = $this->employees($tenantId)->whereKey($employeeId)->first();

        return $employee instanceof Employee ? ['amount' => number_format((float) $employee->base_annual_salary, 2, '.', ''), 'currency' => (string) $employee->currency] : null;
    }

    public function departmentExists(string $tenantId, string $departmentId): bool
    {
        return Department::query()->where('tenant_id', $tenantId)->whereKey($departmentId)->exists();
    }

    /**
     * @return Builder<Employee>
     */
    private function employees(string $tenantId): Builder
    {
        return Employee::query()->with(['department:id,name', 'position:id,title'])->where('tenant_id', $tenantId);
    }

    private function toEmployee(Employee $employee): DirectoryEmployee
    {
        return new DirectoryEmployee(
            (string) $employee->id,
            (string) $employee->full_name,
            (string) $employee->employee_number,
            $employee->position_id !== null ? (string) $employee->position_id : null,
            $employee->position?->title,
            $employee->department_id !== null ? (string) $employee->department_id : null,
            $employee->department?->name,
            ! in_array((string) $employee->employment_status, self::InactiveStatuses, true),
        );
    }

    private function toPosition(Position $position): DirectoryPosition
    {
        return new DirectoryPosition((string) $position->id, (string) $position->code, (string) $position->title, $position->department_id !== null ? (string) $position->department_id : null, $position->department?->name);
    }
}
