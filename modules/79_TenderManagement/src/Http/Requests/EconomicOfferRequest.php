<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Inputs of the economic offer and bid bond simulation.
 */
final class EconomicOfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'discount_percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'estimated_total_cost' => ['nullable', 'numeric', 'min:0'],
            'other_direct_costs' => ['nullable', 'numeric', 'min:0'],
            'company_safety_costs_declared' => ['nullable', 'numeric', 'min:0'],
            'company_labor_costs_declared' => ['nullable', 'numeric', 'min:0'],
            'bid_base_percentage' => ['required', 'numeric'],
            'technical_score_estimate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'best_competitor_discount' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'competitor_discounts' => ['nullable', 'array', 'max:200'],
            'competitor_discounts.*' => ['numeric', 'min:0', 'max:100'],
            'persist' => ['sometimes', 'boolean'],
        ];
    }
}
