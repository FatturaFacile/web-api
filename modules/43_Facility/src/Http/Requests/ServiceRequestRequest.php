<?php

declare(strict_types=1);

namespace Modules\Facility\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Facility\Http\Requests\Concerns\FacilityRules;
use Modules\Facility\Models\FacilityServiceRequest;

/**
 * A facility service request. The building is taken from the space when only the space is given.
 */
final class ServiceRequestRequest extends FormRequest
{
    use FacilityRules;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category' => ['required', Rule::in(FacilityServiceRequest::Categories)],
            'priority' => ['sometimes', Rule::in(FacilityServiceRequest::Priorities)],
            'title' => ['required', 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:4000'],
            'building_id' => ['nullable', 'uuid', $this->tenantOwns('fac_buildings')],
            'space_id' => ['nullable', 'uuid', $this->tenantOwns('fac_spaces')],
            'asset_id' => ['nullable', 'uuid', $this->tenantOwns('eam_fixed_assets')],
        ];
    }
}
