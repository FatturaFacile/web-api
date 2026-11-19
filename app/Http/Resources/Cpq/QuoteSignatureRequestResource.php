<?php

declare(strict_types=1);

namespace App\Http\Resources\Cpq;

use App\Models\QuoteSignatureRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin QuoteSignatureRequest
 */
class QuoteSignatureRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'quote_id' => $this->quote_id,
            'locale' => $this->locale,
            'status' => $this->status,
            'expires_at' => $this->expires_at->toIso8601String(),
            'signer_name' => $this->signer_name,
            'signer_role' => $this->signer_role,
            'purchase_order_number' => $this->purchase_order_number,
            'signature_type' => $this->signature_type,
            'selected_optional_item_ids' => $this->selected_optional_item_ids ?? [],
            'change_request' => $this->change_request,
            'signed_at' => $this->signed_at?->toIso8601String(),
            'ip_address' => $this->ip_address,
            'payload_hash' => $this->payload_hash,
            'pdf_hash' => $this->pdf_hash,
        ];
    }
}
