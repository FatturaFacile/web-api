<?php

declare(strict_types=1);

namespace Modules\Forecasting\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SimulateLiquidityRequest extends FormRequest
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
            'horizon_days' => ['required', 'integer', 'in:91,365'],
            'preview' => ['nullable', 'boolean'],
            'what_if' => ['nullable', 'array'],
            'what_if.global_customer_delay_shift_days' => ['nullable', 'integer', 'between:-60,90'],
            'what_if.revenue_growth_percentage' => ['nullable', 'numeric', 'between:-100,300'],
            'what_if.supplier_payment_extension_days' => ['nullable', 'integer', 'between:0,90'],
            'what_if.interest_rate_delta' => ['nullable', 'numeric', 'between:0,25'],
        ];
    }
}
