<?php

declare(strict_types=1);

namespace Modules\GreenCare\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\GreenCare\Domain\Enums\LicenseType;

final class SaveLicenseRequest extends GreenCareRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'crew_team_member_id' => ['required', 'uuid', $this->inTenant('crew_team_members')],
            'license_type' => ['required', Rule::in(LicenseType::values())],
            'license_number' => ['required', 'string', 'max:80'],
            'issuing_authority' => ['nullable', 'string', 'max:190'],
            'issue_date' => ['required', 'date'],
            'expiry_date' => ['required', 'date', 'after:issue_date'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
