<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Associations\Models\Member;

/**
 * A person as seen in the member area, by the person or by a guardian: no internal notes.
 *
 * @mixin Member
 */
final class AreaMemberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'first_name' => $this->contact?->first_name,
            'last_name' => $this->contact?->last_name,
            'email' => $this->contact?->email,
            'phone' => $this->contact?->phone,
            'is_minor' => $this->isMinor(),
            'birth_date' => $this->birth_date,
            'birth_place' => $this->birth_place,
            'tax_code' => $this->tax_code,
            'allergies' => $this->allergies,
            'emergency_contact' => $this->emergency_contact,
            'custom_fields' => $this->custom_fields ?? [],
        ];
    }
}
