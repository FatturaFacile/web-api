<?php

declare(strict_types=1);

namespace Modules\GreenCare\Http\Requests;

final class InterventionRequest extends GreenCareRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'checkin_at' => ['nullable', 'date'],
            'checkout_at' => ['nullable', 'date', 'after_or_equal:checkin_at'],
            'checkin_gps' => ['nullable', 'array'],
            'checkin_gps.lat' => ['required_with:checkin_gps', 'numeric', 'between:-90,90'],
            'checkin_gps.lng' => ['required_with:checkin_gps', 'numeric', 'between:-180,180'],
            'checkin_gps.accuracy' => ['nullable', 'numeric', 'min:0'],
            'checkout_gps' => ['nullable', 'array'],
            'checkout_gps.lat' => ['required_with:checkout_gps', 'numeric', 'between:-90,90'],
            'checkout_gps.lng' => ['required_with:checkout_gps', 'numeric', 'between:-180,180'],
            'checkout_gps.accuracy' => ['nullable', 'numeric', 'min:0'],
            'crew_hours' => ['nullable', 'array', 'max:50'],
            'crew_hours.*.member_id' => ['required', 'uuid', $this->inTenant('crew_team_members')],
            'crew_hours.*.hours' => ['required', 'numeric', 'min:0', 'max:24'],
            'machinery_hours' => ['nullable', 'array', 'max:50'],
            'machinery_hours.*.machine' => ['required', 'string', 'max:120'],
            'machinery_hours.*.hours' => ['required', 'numeric', 'min:0', 'max:24'],
            'materials' => ['nullable', 'array', 'max:200'],
            'materials.*.product_id' => ['required', 'uuid', $this->inTenant('products')],
            'materials.*.quantity' => ['required', 'numeric', 'gt:0', 'max:9999999'],
            'materials.*.warehouse_id' => ['nullable', 'uuid', $this->inTenant('warehouses')],
            'counts_toward_contract_passage' => ['sometimes', 'boolean'],
            'green_waste_weight_kg' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'waste_code' => ['nullable', 'string', 'max:30'],
            'compost_facility_id' => ['nullable', 'uuid', $this->inTenant('contacts')],
        ];
    }
}
