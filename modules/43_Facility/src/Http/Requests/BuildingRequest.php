<?php

declare(strict_types=1);

namespace Modules\Facility\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Facility\Http\Requests\Concerns\FacilityRules;

/**
 * A building of the register.
 */
final class BuildingRequest extends FormRequest
{
    use FacilityRules;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:30', $this->uniqueInTenant('fac_buildings', 'code')],
            'name' => ['required', 'string', 'max:150'],
            'city' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'size:2', 'alpha'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
