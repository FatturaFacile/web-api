<?php

declare(strict_types=1);

namespace Modules\Forecasting\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class TrainPaymentModelsRequest extends FormRequest
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
            'half_life_days' => ['nullable', 'integer', 'min:7', 'max:365'],
            'lookback_months' => ['nullable', 'integer', 'min:6', 'max:60'],
        ];
    }
}
