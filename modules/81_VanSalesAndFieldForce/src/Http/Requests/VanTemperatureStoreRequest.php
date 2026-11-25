<?php

declare(strict_types=1);

namespace Modules\VanSalesAndFieldForce\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class VanTemperatureStoreRequest extends FormRequest
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
            'temp_celsius' => ['required', 'numeric', 'min:-40', 'max:50'],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }
}
