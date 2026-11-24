<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Procurement\Models\SupplierDocument;

/**
 * A qualification document of a supplier with its expiry.
 *
 * @mixin SupplierDocument
 */
final class SupplierDocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'supplier_id' => (string) $this->supplier_id,
            'document_type' => $this->document_type,
            'reference' => $this->reference,
            'issued_at' => $this->issued_at?->toDateString(),
            'expires_at' => $this->expires_at?->toDateString(),
            'is_mandatory' => (bool) $this->is_mandatory,
            'is_expired' => $this->isExpired(),
            'attachment_id' => $this->attachment_id,
            'notes' => $this->notes,
        ];
    }
}
