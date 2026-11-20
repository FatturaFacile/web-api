<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\DigitalPreservation\Infrastructure\Models\PreservationBatchItem;

/**
 * @mixin PreservationBatchItem
 */
final class PreservationItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'batch_id' => $this->preservation_batch_id,
            'batch_number' => $this->relationLoaded('batch') ? $this->batch->batch_number : null,
            'document_uuid' => $this->document_uuid,
            'document_type' => $this->document_type,
            'document_number' => $this->document_number,
            'document_date' => $this->document_date?->toDateString(),
            'counterparty_name' => $this->counterparty_name,
            'counterparty_tax_id' => $this->counterparty_tax_id,
            'original_filename' => $this->original_filename,
            'archived_filename' => $this->archived_filename,
            'mime_type' => $this->mime_type,
            'is_pdfa_compliant' => $this->is_pdfa_compliant,
            'pdfa_conformance_level' => $this->pdfa_conformance_level,
            'file_size_bytes' => $this->file_size_bytes,
            'sha256_hash' => $this->sha256_hash,
            'retention_until' => $this->retention_until?->toDateString(),
            'jurisdiction' => $this->relationLoaded('batch') ? $this->batch->jurisdiction->value : null,
        ];
    }
}
