<?php

declare(strict_types=1);

namespace Modules\Facility\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Facility\Http\Requests\Concerns\FacilityRules;

/**
 * Links an existing maintenance work order of the asset management module to a service request.
 */
final class LinkWorkOrderRequest extends FormRequest
{
    use FacilityRules;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'work_order_id' => ['required', 'uuid'],
        ];
    }
}
