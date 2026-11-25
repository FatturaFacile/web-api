<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\FranchiseNetwork\Domain\Enums\StartupMode;
use Modules\FranchiseNetwork\Http\Requests\Concerns\ScopesToTenant;

final class SaveFormulaRequest extends FormRequest
{
    use ScopesToTenant;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'code' => [$required, 'string', 'max:40', $this->tenantUnique('frn_formulas', 'code')->ignore($this->route('id'))],
            'labels' => [$required, 'array'],
            'labels.*' => ['string', 'max:160'],
            'descriptions' => ['nullable', 'array'],
            'descriptions.*' => ['string', 'max:500'],
            'royalty_tiers' => [$required, 'array', 'min:1', 'max:12'],
            'royalty_tiers.*.from' => ['required', 'numeric', 'min:0'],
            'royalty_tiers.*.to' => ['nullable', 'numeric', 'gt:royalty_tiers.*.from'],
            'royalty_tiers.*.rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'marketing_tiers' => [$required, 'array', 'min:1', 'max:12'],
            'marketing_tiers.*.from' => ['required', 'numeric', 'min:0'],
            'marketing_tiers.*.to' => ['nullable', 'numeric', 'gt:marketing_tiers.*.from'],
            'marketing_tiers.*.rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'minimum_guarantee' => ['sometimes', 'numeric', 'min:0'],
            'startup_months' => ['sometimes', 'integer', 'min:0', 'max:36'],
            'startup_mode' => ['sometimes', Rule::in(StartupMode::values())],
            'startup_reduction_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'ramp_start_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'startup_applies_to_marketing' => ['sometimes', 'boolean'],
            'currency' => ['sometimes', 'string', Rule::in(['EUR', 'CHF'])],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
