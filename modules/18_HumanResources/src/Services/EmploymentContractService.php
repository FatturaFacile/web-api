<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\HumanResources\Exceptions\HumanResourcesException;
use Modules\HumanResources\Models\ContractSalaryComponent;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\EmploymentContract;
use Modules\HumanResources\Support\Decimal;

/**
 * The contract history of an employee. A new contract (amendment, renewal, change of hours or level) supersedes the
 * active one, which ends the day before and stays in the history; the employee master data follows the active
 * contract. Salary components belong to one contract and carry their own validity. Everything runs under the row lock
 * of the employee.
 */
final class EmploymentContractService
{
    /**
     * Start a new contract for the employee.
     *
     * @param  array<string, mixed>  $values  validated contract attributes and an optional list of salary_components
     */
    public function create(Employee $employee, array $values): EmploymentContract
    {
        return DB::transaction(function () use ($employee, $values): EmploymentContract {
            $locked = Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $start = CarbonImmutable::parse((string) $values['start_date']);
            $previous = EmploymentContract::query()->where('employee_id', $locked->id)->where('is_active', true)->lockForUpdate()->first();

            if ($previous !== null && ! $start->isAfter($previous->start_date)) {
                throw new HumanResourcesException('Il nuovo contratto deve iniziare dopo l\'inizio del contratto attivo ('.$previous->start_date->format('Y-m-d').').');
            }

            $weeklyHours = Decimal::of($values['weekly_hours'] ?? '40');
            $salary = Decimal::of($values['base_annual_salary'] ?? '0');

            $contract = EmploymentContract::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $locked->tenant_id,
                'company_id' => $locked->company_id,
                'employee_id' => $locked->id,
                'contract_number' => $values['contract_number'] ?? 'CTR-'.$locked->employee_number.'-'.$start->format('Ymd'),
                'contract_type' => $values['contract_type'],
                'collective_agreement' => $values['collective_agreement'] ?? null,
                'collective_agreement_id' => $values['collective_agreement_id'] ?? null,
                'collective_agreement_level_id' => $values['collective_agreement_level_id'] ?? null,
                'level_code' => $values['level_code'] ?? null,
                'job_title' => $values['job_title'],
                'job_grade_id' => $values['job_grade_id'] ?? null,
                'start_date' => $start->format('Y-m-d'),
                'end_date' => $values['end_date'] ?? null,
                'probation_end_date' => $values['probation_end_date'] ?? null,
                'weekly_hours' => Decimal::round($weeklyHours),
                'fte_percentage' => Decimal::round(Decimal::of($values['fte_percentage'] ?? '100')),
                'base_annual_salary' => Decimal::round($salary),
                'hourly_rate' => Decimal::round(Decimal::of($values['hourly_rate'] ?? '0')),
                'loaded_hourly_cost' => Decimal::round(Decimal::of($values['loaded_hourly_cost'] ?? '0')),
                'currency' => $values['currency'] ?? $locked->currency,
                'status' => 'active',
                'is_active' => true,
                'terms' => $values['terms'] ?? null,
                'notes' => $values['notes'] ?? null,
            ]);

            if ($previous !== null) {
                $previous->update([
                    'is_active' => false,
                    'status' => 'amended',
                    'end_date' => $previous->end_date !== null && $previous->end_date->lessThan($start) ? $previous->end_date->format('Y-m-d') : $start->subDay()->format('Y-m-d'),
                    'superseded_by_contract_id' => $contract->id,
                ]);

                ContractSalaryComponent::query()->where('contract_id', $previous->id)
                    ->where(static fn ($query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $start->format('Y-m-d')))
                    ->update(['valid_to' => $start->subDay()->format('Y-m-d')]);
            }

            foreach ($values['salary_components'] ?? [] as $component) {
                $this->storeComponent($contract, $component);
            }

            $locked->update([
                'base_annual_salary' => Decimal::round($salary),
                'fte_ratio' => Decimal::round(Decimal::div(Decimal::of($values['fte_percentage'] ?? '100'), '100')),
                'employment_type' => $values['employment_type'] ?? $locked->employment_type,
                'job_grade_id' => $values['job_grade_id'] ?? $locked->job_grade_id,
            ]);

            return $contract->load('salaryComponents');
        });
    }

    /**
     * Change the descriptive data of a contract. Pay, hours and dates of a contract that has started change only
     * through a new contract, so the history stays true.
     *
     * @param  array<string, mixed>  $values
     */
    public function update(EmploymentContract $contract, array $values): EmploymentContract
    {
        return DB::transaction(function () use ($contract, $values): EmploymentContract {
            $locked = EmploymentContract::query()->whereKey($contract->id)->lockForUpdate()->firstOrFail();

            if (! $locked->is_active) {
                throw new HumanResourcesException('Un contratto sostituito o cessato non si modifica: resta nello storico.');
            }

            $locked->update($values);

            return $locked->load('salaryComponents');
        });
    }

    /**
     * Add a salary component to a contract.
     *
     * @param  array<string, mixed>  $values
     */
    public function addComponent(EmploymentContract $contract, array $values): ContractSalaryComponent
    {
        return DB::transaction(function () use ($contract, $values): ContractSalaryComponent {
            $locked = EmploymentContract::query()->whereKey($contract->id)->lockForUpdate()->firstOrFail();

            if (! $locked->is_active) {
                throw new HumanResourcesException('Le voci retributive si aggiungono solo al contratto attivo.');
            }

            return $this->storeComponent($locked, $values);
        });
    }

    /**
     * Change a salary component of the active contract.
     *
     * @param  array<string, mixed>  $values
     */
    public function updateComponent(ContractSalaryComponent $component, array $values): ContractSalaryComponent
    {
        return DB::transaction(function () use ($component, $values): ContractSalaryComponent {
            $locked = ContractSalaryComponent::query()->whereKey($component->id)->lockForUpdate()->firstOrFail();
            $this->assertActiveContract($locked);

            if (isset($values['amount'])) {
                $values['amount'] = Decimal::round(Decimal::of((string) $values['amount']));
            }

            $locked->update($values);

            return $locked;
        });
    }

    /**
     * Remove a salary component of the active contract (soft delete, the history keeps it).
     */
    public function deleteComponent(ContractSalaryComponent $component): void
    {
        DB::transaction(function () use ($component): void {
            $locked = ContractSalaryComponent::query()->whereKey($component->id)->lockForUpdate()->firstOrFail();
            $this->assertActiveContract($locked);
            $locked->delete();
        });
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function storeComponent(EmploymentContract $contract, array $values): ContractSalaryComponent
    {
        return ContractSalaryComponent::create([
            'tenant_id' => $contract->tenant_id,
            'contract_id' => $contract->id,
            'wage_type_code' => $values['wage_type_code'],
            'description' => $values['description'],
            'amount' => Decimal::round(Decimal::of((string) $values['amount'])),
            'frequency' => $values['frequency'] ?? ContractSalaryComponent::FrequencyMonthly,
            'valid_from' => $values['valid_from'] ?? $contract->start_date->format('Y-m-d'),
            'valid_to' => $values['valid_to'] ?? null,
        ]);
    }

    private function assertActiveContract(ContractSalaryComponent $component): void
    {
        $contract = EmploymentContract::query()->find($component->contract_id);

        if ($contract === null || ! $contract->is_active) {
            throw new HumanResourcesException('Le voci di un contratto sostituito restano nello storico e non si modificano.');
        }
    }
}
