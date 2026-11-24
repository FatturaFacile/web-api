<?php

declare(strict_types=1);

namespace App\Http\Resources\Procurement\Rfq;

use App\Domain\Procurement\Rfq\Models\RfqAttachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RfqAttachment
 */
final class RfqAttachmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'rfq_id' => (string) $this->rfq_id,
            'file_name' => (string) $this->file_name,
            'mime_type' => (string) $this->mime_type,
            'file_size_bytes' => (int) $this->file_size_bytes,
            'sha256_hash' => (string) $this->sha256_hash,
            'is_visible_to_suppliers' => (bool) $this->is_visible_to_suppliers,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
