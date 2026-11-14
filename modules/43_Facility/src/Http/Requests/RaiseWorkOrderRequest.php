<?php

declare(strict_types=1);

namespace Modules\Facility\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Facility\Http\Requests\Concerns\FacilityRules;

/**
 * Raises a corrective work order for the asset of a service request; asset_id is needed only when the request has none.
 */
final class RaiseWorkOrderRequest extends FormRequest
{
    use FacilityRules;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'asset_id' => ['nullable', 'uuid', $this->tenantOwns('eam_fixed_assets')],
        ];
    }
}
