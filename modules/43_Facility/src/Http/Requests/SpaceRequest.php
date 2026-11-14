<?php

declare(strict_types=1);

namespace Modules\Facility\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Facility\Http\Requests\Concerns\FacilityRules;
use Modules\Facility\Models\FacilitySpace;

/**
 * A floor or space of a building. The building of an existing space never changes.
 */
final class SpaceRequest extends FormRequest
{
    use FacilityRules;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'building_id' => ['required', 'uuid', $this->tenantOwns('fac_buildings')],
            'parent_id' => ['nullable', 'uuid', $this->tenantOwns('fac_spaces')],
            'kind' => ['required', Rule::in(FacilitySpace::Kinds)],
            'code' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:150'],
            'floor_level' => ['nullable', 'integer', 'between:-20,200'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'area_sqm' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'is_bookable' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'amenities' => ['nullable', 'array', 'max:30'],
            'amenities.*' => ['string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
