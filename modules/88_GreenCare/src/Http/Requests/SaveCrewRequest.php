<?php

declare(strict_types=1);

namespace Modules\GreenCare\Http\Requests;

use Modules\GreenCare\Domain\Enums\EquipmentType;

final class SaveCrewRequest extends GreenCareRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $id = $this->route('id');
        $rules = [
            'code' => ['required', 'string', 'max:40', $this->uniqueInTenant('crew_teams', 'code', is_string($id) ? $id : null)],
            'name' => ['required', 'string', 'max:190'],
            'default_vehicle_plate' => ['nullable', 'string', 'max:20'],
            'default_trailer_plate' => ['nullable', 'string', 'max:20'],
            'equipment' => ['nullable', 'array'],
            'daily_capacity_minutes' => ['nullable', 'integer', 'min:30', 'max:1440'],
            'depot_latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:depot_longitude'],
            'depot_longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:depot_latitude'],
            'is_active' => ['sometimes', 'boolean'],
            'members' => ['sometimes', 'array', 'max:50'],
            'members.*.id' => ['nullable', 'uuid', $this->inTenant('crew_team_members')],
            'members.*.name' => ['required', 'string', 'max:190'],
            'members.*.user_id' => ['nullable', 'uuid'],
            'members.*.role' => ['nullable', 'string', 'max:60'],
            'members.*.hourly_rate' => ['nullable', 'numeric', 'min:0', 'max:99999'],
        ];

        foreach (EquipmentType::cases() as $type) {
            $rules['equipment.'.$type->value] = ['nullable', 'integer', 'min:0', 'max:1000'];
        }

        return $rules;
    }
}
