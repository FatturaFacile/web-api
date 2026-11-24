<?php

declare(strict_types=1);

namespace Modules\Facility\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Facility\Http\Requests\Concerns\FacilityRules;

/**
 * Runs the inspection scheduler by hand for a day (default today) and optionally for one template.
 */
final class GenerateInspectionsRequest extends FormRequest
{
    use FacilityRules;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'as_of' => ['nullable', 'date_format:Y-m-d'],
            'template_id' => ['nullable', 'uuid', $this->tenantOwns('fac_inspection_templates')],
        ];
    }
}
