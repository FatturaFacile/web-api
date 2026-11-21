<?php

declare(strict_types=1);

namespace App\Http\Resources\Procurement\Rfq;

use App\Domain\Procurement\Rfq\Models\RfqSupplierInvitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RfqSupplierInvitation
 */
final class RfqInvitationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'rfq_id' => (string) $this->rfq_id,
            'supplier_id' => (string) $this->supplier_id,
            'supplier_name' => $this->whenLoaded('supplier', fn () => $this->supplier?->company_name),
            'supplier_code' => $this->whenLoaded('supplier', fn () => $this->supplier?->supplier_code),
            'supplier_rating' => $this->whenLoaded('supplier', fn () => $this->supplier?->rating),
            'status' => (string) $this->status,
            'token_expires_at' => $this->token_expires_at?->toIso8601String(),
            'invited_at' => $this->invited_at?->toIso8601String(),
            'first_viewed_at' => $this->first_viewed_at?->toIso8601String(),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'responded_at' => $this->responded_at?->toIso8601String(),
            'declined_reason' => $this->declined_reason,
            'notified_outcome_at' => $this->notified_outcome_at?->toIso8601String(),
        ];
    }
}
