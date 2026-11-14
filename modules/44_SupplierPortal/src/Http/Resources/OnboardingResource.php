<?php

declare(strict_types=1);

namespace Modules\SupplierPortal\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\SupplierPortal\Models\SupplierOnboarding;

/**
 * The onboarding of a supplier with its status, registration data and decision.
 *
 * @mixin SupplierOnboarding
 */
final class OnboardingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'supplier_id' => $this->supplier_id,
            'supplier_name' => $this->supplier?->company_name,
            'supplier_vat_number' => $this->supplier?->vat_number,
            'qualification_status' => $this->supplier?->qualification_status,
            'status' => $this->status,
            'country' => $this->country,
            'invitation_email' => $this->invitation_email,
            'invited_at' => $this->invited_at?->toIso8601String(),
            'registration' => $this->registration,
            'registration_submitted_at' => $this->registration_submitted_at?->toIso8601String(),
            'documents_submitted_at' => $this->documents_submitted_at?->toIso8601String(),
            'review_started_at' => $this->review_started_at?->toIso8601String(),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'decision_notes' => $this->decision_notes,
            'status_reason' => $this->status_reason,
            'allowed_transitions' => array_map(static fn ($status): string => $status->value, $this->statusEnum()->transitions()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
