<?php

declare(strict_types=1);

namespace Modules\Forecasting\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class GenerateDemandForecastRequest extends FormRequest
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
            'product_id' => ['nullable', 'uuid'],
            'warehouse_id' => ['nullable', 'uuid'],
            'periods_ahead' => ['nullable', 'integer', 'between:1,12'],
            'service_level' => ['nullable', 'numeric', 'gt:0.5', 'lt:1'],
        ];
    }
}
