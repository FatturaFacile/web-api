<?php

declare(strict_types=1);

namespace Modules\Forecasting\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ApplyReorderRulesRequest extends FormRequest
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
            'forecast_ids' => ['required_without:product_ids', 'array', 'max:200'],
            'forecast_ids.*' => ['uuid'],
            'product_ids' => ['required_without:forecast_ids', 'array', 'max:200'],
            'product_ids.*' => ['uuid'],
        ];
    }
}
