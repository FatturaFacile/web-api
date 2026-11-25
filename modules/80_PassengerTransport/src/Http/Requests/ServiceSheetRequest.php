<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Times, kilometres and passenger manifest of a service sheet; the generate action also carries what to do with the sheet.
 */
final class ServiceSheetRequest extends FormRequest
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
            'garage_departure_at' => ['nullable', 'date'],
            'garage_departure_km' => ['nullable', 'integer', 'min:0'],
            'service_start_at' => ['nullable', 'date'],
            'service_start_km' => ['nullable', 'integer', 'min:0'],
            'service_end_at' => ['nullable', 'date', 'after_or_equal:service_start_at'],
            'service_end_km' => ['nullable', 'integer', 'min:0'],
            'garage_return_at' => ['nullable', 'date', 'after_or_equal:service_end_at'],
            'garage_return_km' => ['nullable', 'integer', 'min:0'],
            'passenger_manifest' => ['nullable', 'array', 'max:80'],
            'passenger_manifest.*.name' => ['required_with:passenger_manifest', 'string', 'max:160'],
            'passenger_manifest.*.birth_date' => ['nullable', 'date'],
            'passenger_manifest.*.document' => ['nullable', 'string', 'max:60'],
            'passenger_manifest.*.nationality' => ['nullable', 'string', 'max:60'],
            'close' => ['sometimes', 'boolean'],
            'transmit' => ['sometimes', 'boolean'],
            'invoice' => ['sometimes', 'boolean'],
        ];
    }
}
