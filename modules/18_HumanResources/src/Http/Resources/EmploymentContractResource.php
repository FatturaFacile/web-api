<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\HumanResources\Models\ContractSalaryComponent;
use Modules\HumanResources\Models\EmploymentContract;

/**
 * A contract of the history with its salary components. Amounts are decimal strings.
 *
 * @mixin EmploymentContract
 */
final class EmploymentContractResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var EmploymentContract $contract */
        $contract = $this->resource;

        return [
            'id' => $contract->id,
            'employee_id' => $contract->employee_id,
            'contract_number' => $contract->contract_number,
            'contract_type' => $contract->contract_type,
            'job_title' => $contract->job_title,
            'job_grade_id' => $contract->job_grade_id,
            'collective_agreement' => $contract->collective_agreement,
            'collective_agreement_id' => $contract->collective_agreement_id,
            'collective_agreement_level_id' => $contract->collective_agreement_level_id,
            'level_code' => $contract->level_code,
            'start_date' => $contract->start_date->format('Y-m-d'),
            'end_date' => $contract->end_date?->format('Y-m-d'),
            'probation_end_date' => $contract->probation_end_date?->format('Y-m-d'),
            'weekly_hours' => (string) $contract->weekly_hours,
            'fte_percentage' => (string) $contract->fte_percentage,
            'base_annual_salary' => (string) $contract->base_annual_salary,
            'hourly_rate' => (string) $contract->hourly_rate,
            'loaded_hourly_cost' => (string) $contract->loaded_hourly_cost,
            'currency' => $contract->currency,
            'status' => $contract->status,
            'is_active' => (bool) $contract->is_active,
            'superseded_by_contract_id' => $contract->superseded_by_contract_id,
            'terms' => $contract->terms,
            'notes' => $contract->notes,
            'salary_components' => $contract->relationLoaded('salaryComponents')
                ? $contract->salaryComponents->map(static fn (ContractSalaryComponent $component): array => self::component($component))->values()->all()
                : [],
        ];
    }

    /**
     * The public shape of a salary component.
     *
     * @return array<string, mixed>
     */
    public static function component(ContractSalaryComponent $component): array
    {
        return [
            'id' => $component->id,
            'contract_id' => $component->contract_id,
            'wage_type_code' => $component->wage_type_code,
            'description' => $component->description,
            'amount' => (string) $component->amount,
            'frequency' => $component->frequency,
            'valid_from' => $component->valid_from->format('Y-m-d'),
            'valid_to' => $component->valid_to?->format('Y-m-d'),
        ];
    }
}
