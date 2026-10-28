<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreSupplierEvaluationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'evaluation_period_start' => ['required', 'date'],
            'evaluation_period_end' => ['required', 'date', 'after_or_equal:evaluation_period_start'],
            'on_time_delivery_rate' => ['required', 'numeric', 'between:0,100'],
            'quality_acceptance_rate' => ['required', 'numeric', 'between:0,100'],
            'price_competitiveness_score' => ['required', 'numeric', 'between:0,100'],
            'overall_rating' => ['required', 'string', 'in:A,B,C,D'],
            'is_approved_vendor' => ['required', 'boolean'],
            'comments' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
