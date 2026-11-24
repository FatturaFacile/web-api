<?php

declare(strict_types=1);

namespace Modules\SupplierPortal\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\SupplierPortal\Models\SupplierDocumentSubmission;

/**
 * A document of a supplier with its review status and expiry.
 *
 * @mixin SupplierDocumentSubmission
 */
final class DocumentSubmissionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $daysLeft = $this->expires_at === null ? null : (int) now()->startOfDay()->diffInDays($this->expires_at->copy()->startOfDay(), false);

        return [
            'id' => $this->id,
            'supplier_id' => $this->supplier_id,
            'supplier_name' => $this->whenLoaded('supplier', fn () => $this->supplier?->company_name),
            'onboarding_id' => $this->onboarding_id,
            'requirement_id' => $this->requirement_id,
            'requirement_code' => $this->whenLoaded('requirement', fn () => $this->requirement?->code),
            'requirement_name' => $this->whenLoaded('requirement', fn () => $this->requirement?->name),
            'is_mandatory' => $this->whenLoaded('requirement', fn () => (bool) $this->requirement?->is_mandatory),
            'status' => $this->status,
            'reference' => $this->reference,
            'issued_at' => $this->issued_at?->toDateString(),
            'expires_at' => $this->expires_at?->toDateString(),
            'days_to_expiry' => $daysLeft,
            'is_expired' => $this->isExpired(),
            'has_file' => $this->file_path !== null,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'file_size' => $this->file_size,
            'submitted_via' => $this->submitted_via,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
