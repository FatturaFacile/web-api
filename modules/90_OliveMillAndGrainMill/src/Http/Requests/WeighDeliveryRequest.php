<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Weighbridge reading of a delivery (manual or from a scale integration).
 */
final class WeighDeliveryRequest extends FormRequest
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
            'gross_weight_kg' => ['required', 'numeric', 'gt:0'],
            'tare_kg' => ['required', 'numeric', 'min:0'],
            'crate_count' => ['required', 'integer', 'min:0'],
            'crate_unit_tare_kg' => ['required', 'numeric', 'min:0'],
            'weighbridge_ticket' => ['nullable', 'string', 'max:60'],
            'scale_payload' => ['nullable', 'array'],
            'scale_read_at' => ['nullable', 'date'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'tare_kg' => $this->input('tare_kg', 0),
            'crate_count' => $this->input('crate_count', 0),
            'crate_unit_tare_kg' => $this->input('crate_unit_tare_kg', 0),
        ]);
    }
}
