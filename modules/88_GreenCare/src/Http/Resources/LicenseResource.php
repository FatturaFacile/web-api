<?php

declare(strict_types=1);

namespace Modules\GreenCare\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\GreenCare\Models\OperatorPhytosanitaryLicense;

/**
 * @mixin OperatorPhytosanitaryLicense
 */
final class LicenseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $today = now()->startOfDay();

        return [
            'id' => $this->id,
            'crew_team_member_id' => $this->crew_team_member_id,
            'operator_name' => $this->operator->name ?? null,
            'license_type' => $this->license_type->value,
            'jurisdiction' => $this->jurisdiction,
            'license_number' => $this->license_number,
            'issuing_authority' => $this->issuing_authority,
            'issue_date' => $this->issue_date->format('Y-m-d'),
            'expiry_date' => $this->expiry_date->format('Y-m-d'),
            'is_active' => $this->is_active,
            'is_valid' => $this->isValidOn($today),
            'days_to_expiry' => (int) $today->diffInDays($this->expiry_date, false),
        ];
    }
}
