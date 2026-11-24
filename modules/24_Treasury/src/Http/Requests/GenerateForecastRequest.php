<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class GenerateForecastRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'scenario_code' => ['nullable', 'string', 'max:50'],
            'horizon_days' => ['nullable', 'integer', 'in:30,60,90,180,360'],
            'calculation_date' => ['nullable', 'date'],
        ];
    }
}
