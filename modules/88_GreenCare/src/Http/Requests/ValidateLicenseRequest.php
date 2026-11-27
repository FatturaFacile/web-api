<?php

declare(strict_types=1);

namespace Modules\GreenCare\Http\Requests;

final class ValidateLicenseRequest extends GreenCareRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'operator_id' => ['required', 'uuid', $this->inTenant('crew_team_members')],
            'treatment_date' => ['required', 'date'],
            'site_id' => ['nullable', 'uuid', $this->inTenant('green_space_sites')],
            'jurisdiction' => ['nullable', 'in:IT,CH'],
        ];
    }
}
