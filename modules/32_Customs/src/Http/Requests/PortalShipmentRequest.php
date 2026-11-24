<?php

declare(strict_types=1);

namespace Modules\Customs\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class PortalShipmentRequest extends FormRequest
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
            'service_type' => ['required', 'string', 'max:40'],
            'direction' => ['required', ...CustomsRules::direction()],
            'origin_country' => ['required', ...CustomsRules::country()],
            'destination_country' => ['required', ...CustomsRules::country()],
            'gross_weight_kg' => ['required', 'numeric', 'gt:0'],
            'volume_m3' => ['sometimes', 'numeric', 'min:0'],
            'packages' => ['required', 'integer', 'min:1'],
            'pickup_on' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
