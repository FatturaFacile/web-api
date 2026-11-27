<?php

declare(strict_types=1);

namespace Modules\DownPayments\Http\Requests\Concerns;

use Illuminate\Validation\Rule;
use Modules\DownPayments\Domain\Enums\CalculationMode;
use Modules\DownPayments\Domain\Enums\InstallmentTrigger;
use Modules\DownPayments\Domain\Enums\InstrumentType;
use Modules\DownPayments\Domain\Enums\SourceType;
use Modules\DownPayments\Services\InstallmentPlanService;

/**
 * The source and the installment plan of a chain, shared by the plan preview and the creation of a chain.
 */
trait PlanRules
{
    /**
     * @return array<string, mixed>
     */
    private function planRules(): array
    {
        return [
            'source_type' => ['required', Rule::enum(SourceType::class)],
            'source_id' => ['required', 'uuid'],
            'template' => ['nullable', 'string', Rule::in([InstallmentPlanService::TEMPLATE_30_40_30, InstallmentPlanService::TEMPLATE_50_50, InstallmentPlanService::TEMPLATE_CAPARRA_BALANCE])],
            'installments' => ['nullable', 'array', 'max:24'],
            'installments.*.milestone_label' => ['nullable', 'string', 'max:200'],
            'installments.*.trigger_type' => ['required', Rule::enum(InstallmentTrigger::class)],
            'installments.*.due_date' => ['nullable', 'date'],
            'installments.*.calculation_mode' => ['required', Rule::enum(CalculationMode::class)],
            'installments.*.percentage' => ['nullable', 'numeric', 'gt:0', 'max:100'],
            'installments.*.amount' => ['nullable', 'numeric', 'decimal:0,2', 'gt:0'],
            'installments.*.instrument_type' => ['required', Rule::enum(InstrumentType::class)],
            'contract' => ['nullable', 'array', 'required_if:source_type,contract'],
            'contract.customer_id' => ['nullable', 'uuid', 'required_if:source_type,contract'],
            'contract.number' => ['nullable', 'string', 'max:60'],
            'contract.currency' => ['nullable', 'string', 'size:3'],
            'contract.lines' => ['nullable', 'array', 'required_if:source_type,contract'],
            'contract.lines.*.description' => ['nullable', 'string', 'max:500'],
            'contract.lines.*.vat_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'contract.lines.*.net' => ['required', 'numeric', 'decimal:0,2', 'gt:0'],
        ];
    }
}
