<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Modules\Associations\Models\Member;
use Modules\Associations\Support\AssociationCapability;

/**
 * A person as the staff sees it. Health data needs its own capability; what an accountant does not need for fees and
 * receipts stays with those who manage the registry.
 *
 * @mixin Member
 */
final class MemberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $canSeeHealth = Gate::allows(AssociationCapability::Health->value);
        $canManage = Gate::allows(AssociationCapability::Manage->value);

        return [
            'id' => $this->id,
            'contact_id' => $this->contact_id,
            'family_id' => $this->family_id,
            'status' => $this->status,
            'first_name' => $this->contact?->first_name,
            'last_name' => $this->contact?->last_name,
            'company_name' => $this->contact?->company_name,
            'email' => $this->contact?->email,
            'phone' => $this->contact?->phone,
            'joined_on' => $this->joined_on?->toDateString(),
            'left_on' => $this->left_on?->toDateString(),
            'is_minor' => $this->isMinor(),
            'birth_date' => $this->birth_date,
            'birth_place' => $this->birth_place,
            'gender' => $this->gender,
            'tax_code' => $this->tax_code,
            'identity_document' => $this->when($canManage, $this->identity_document),
            'custom_fields' => $this->custom_fields ?? [],
            'internal_notes' => $this->when($canManage, $this->internal_notes),
            'emergency_contact' => $this->when($canManage, $this->emergency_contact),
            'health_notes' => $this->when($canSeeHealth, $this->health_notes),
            'allergies' => $this->when($canSeeHealth, $this->allergies),
            'registry_assignments' => $this->whenLoaded('registryAssignments'),
            'social_roles' => $this->whenLoaded('socialRoles'),
            'memberships' => $this->whenLoaded('memberships'),
        ];
    }
}
