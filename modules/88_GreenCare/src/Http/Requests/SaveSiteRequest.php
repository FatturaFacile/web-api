<?php

declare(strict_types=1);

namespace Modules\GreenCare\Http\Requests;

use Closure;

final class SaveSiteRequest extends GreenCareRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $id = $this->route('id');

        return [
            'code' => ['required', 'string', 'max:40', $this->uniqueInTenant('green_space_sites', 'code', is_string($id) ? $id : null)],
            'name' => ['required', 'string', 'max:190'],
            'customer_id' => ['required', 'uuid', $this->inTenant('contacts')],
            'condominium_administrator_id' => ['nullable', 'uuid', $this->inTenant('contacts')],
            'jurisdiction' => ['sometimes', 'string', 'in:IT,CH'],
            'address' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'geo_polygon_coordinates' => ['nullable', 'array', $this->polygonRule()],
            'lawn_area_sqm' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'hedge_length_meters' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'tree_count' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'irrigation_system_specs' => ['nullable', 'array'],
            'irrigation_system_specs.zones' => ['nullable', 'integer', 'min:0', 'max:500'],
            'irrigation_system_specs.controller_type' => ['nullable', 'string', 'max:120'],
            'irrigation_system_specs.winterized' => ['nullable', 'boolean'],
            'irrigation_system_specs.notes' => ['nullable', 'string', 'max:2000'],
            'access_gate_code' => ['nullable', 'string', 'max:120'],
            'access_notes' => ['nullable', 'string', 'max:2000'],
            'botanical_inventory' => ['nullable', 'array', 'max:500'],
            'botanical_inventory.*.species' => ['required_with:botanical_inventory', 'string', 'max:190'],
            'botanical_inventory.*.planting_date' => ['nullable', 'date'],
            'botanical_inventory.*.pruning_schedule' => ['nullable', 'string', 'max:190'],
            'botanical_inventory.*.phytosanitary_notes' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * A GeoJSON Polygon: `type` Polygon and a ring of at least 4 [lng, lat] positions.
     */
    private function polygonRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_array($value) || ($value['type'] ?? null) !== 'Polygon' || ! is_array($value['coordinates'][0] ?? null) || count($value['coordinates'][0]) < 4) {
                $fail((string) __('green_care.validation.polygon'));

                return;
            }

            foreach ($value['coordinates'][0] as $position) {
                if (! is_array($position) || count($position) < 2 || ! is_numeric($position[0]) || ! is_numeric($position[1])) {
                    $fail((string) __('green_care.validation.polygon'));

                    return;
                }
            }
        };
    }
}
