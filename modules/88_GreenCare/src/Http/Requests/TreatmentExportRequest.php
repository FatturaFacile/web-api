<?php

declare(strict_types=1);

namespace Modules\GreenCare\Http\Requests;

/**
 * Filters of the inspection export of the phytosanitary register: period, site or operator.
 */
final class TreatmentExportRequest extends GreenCareRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'site_id' => ['nullable', 'uuid', $this->inTenant('green_space_sites')],
            'operator_id' => ['nullable', 'uuid', $this->inTenant('crew_team_members')],
        ];
    }
}
