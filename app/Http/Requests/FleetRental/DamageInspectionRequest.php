<?php

declare(strict_types=1);

namespace App\Http\Requests\FleetRental;

use Illuminate\Foundation\Http\FormRequest;

class DamageInspectionRequest extends FormRequest
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
            'rental_booking_id' => ['required', 'uuid'],
            'mileage' => ['required', 'integer', 'min:0'],
            'fuel_level' => ['required', 'string'],
            'damage_markers' => ['nullable', 'array'],
            'photos' => ['nullable', 'array'],
        ];
    }
}
