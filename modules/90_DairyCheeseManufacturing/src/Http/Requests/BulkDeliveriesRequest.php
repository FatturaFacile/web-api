<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Http\Requests;

final class BulkDeliveriesRequest extends DairyFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'rows' => ['required', 'array', 'min:1', 'max:500'],
            'rows.*.breeder_id' => ['required', 'uuid', $this->existsIn('dairy_breeders')],
            'rows.*.tank_id' => ['required', 'uuid', $this->existsIn('dairy_milk_tanks')],
            'rows.*.collection_date' => ['required', 'date'],
            'rows.*.session' => ['required', 'in:morning,evening'],
            'rows.*.liters' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'rows.*.density' => ['nullable', 'numeric', 'between:1,1.1'],
            'rows.*.temperature_c' => ['nullable', 'numeric', 'between:-5,40'],
            'rows.*.route_code' => ['nullable', 'string', 'max:40'],
            'rows.*.tanker_plate' => ['nullable', 'string', 'max:20'],
            'rows.*.notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
