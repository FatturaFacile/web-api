<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\PassengerTransport\Domain\Enums\BorderCrossing;
use Modules\PassengerTransport\Domain\Enums\VehicleCategory;
use Modules\PassengerTransport\Http\Requests\Concerns\ScopesToTenant;

final class SaveHotelContractRequest extends FormRequest
{
    use ScopesToTenant;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'contact_id' => [$required, 'uuid', $this->tenantExists('contacts')],
            'code' => [$required, 'string', 'max:40', $this->tenantUnique('ptr_hotel_concierge_contracts')->ignore($this->route('id'))],
            'name' => [$required, 'string', 'max:160'],
            'commission_rate' => ['sometimes', 'numeric', 'between:0,100'],
            'currency' => ['sometimes', Rule::in(['CHF', 'EUR'])],
            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'billing_cycle' => ['sometimes', Rule::in(['monthly'])],
            'is_active' => ['sometimes', 'boolean'],
            'rate_card' => ['nullable', 'array', 'max:200'],
            'rate_card.*.route_code' => ['required', 'string', 'max:40', 'distinct'],
            'rate_card.*.origin_label' => ['required', 'string', 'max:160'],
            'rate_card.*.destination_label' => ['required', 'string', 'max:160'],
            'rate_card.*.vehicle_category' => ['required', Rule::in(VehicleCategory::values())],
            'rate_card.*.price' => ['required', 'numeric', 'min:0'],
            'rate_card.*.origin_country' => ['nullable', 'string', 'size:2'],
            'rate_card.*.destination_country' => ['nullable', 'string', 'size:2'],
            'rate_card.*.origin_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'rate_card.*.origin_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'rate_card.*.destination_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'rate_card.*.destination_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'rate_card.*.border_crossing' => ['nullable', Rule::in(BorderCrossing::values())],
            'rate_card.*.total_km' => ['nullable', 'numeric', 'min:0'],
            'rate_card.*.it_km' => ['nullable', 'numeric', 'min:0'],
            'rate_card.*.ch_km' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
