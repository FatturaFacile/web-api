<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Http\Requests;

final class RegisterDeliveryRequest extends DairyFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'breeder_id' => ['required', 'uuid', $this->existsIn('dairy_breeders')],
            'tank_id' => ['required', 'uuid', $this->existsIn('dairy_milk_tanks')],
            'collection_date' => ['required', 'date'],
            'session' => ['required', 'in:morning,evening'],
            'liters' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'density' => ['nullable', 'numeric', 'between:1,1.1'],
            'temperature_c' => ['nullable', 'numeric', 'between:-5,40'],
            'route_code' => ['nullable', 'string', 'max:40'],
            'tanker_plate' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
