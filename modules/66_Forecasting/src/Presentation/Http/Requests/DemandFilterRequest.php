<?php

declare(strict_types=1);

namespace Modules\Forecasting\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class DemandFilterRequest extends FormRequest
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
            'search' => ['nullable', 'string', 'max:100'],
        ];
    }
}
