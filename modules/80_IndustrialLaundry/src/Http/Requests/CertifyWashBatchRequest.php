<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CertifyWashBatchRequest extends FormRequest
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
            'measured_temperature_c' => ['nullable', 'numeric', 'between:0,150'],
            'measured_hold_minutes' => ['nullable', 'integer', 'between:0,1440'],
            'measured_chemical_dosing_ml_per_kg' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'microbiological_cfu_per_25cm2' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'verifier_name' => ['required', 'string', 'max:160'],
        ];
    }
}
