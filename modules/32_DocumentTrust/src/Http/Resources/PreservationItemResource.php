<?php

declare(strict_types=1);

namespace Modules\DocumentTrust\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\DocumentTrust\Models\PreservationItem;

/**
 * API representation of a preserved file and its metadata.
 *
 * @mixin PreservationItem
 */
final class PreservationItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'package_id' => (string) $this->package_id,
            'package_status' => $this->resource->relationLoaded('package') ? $this->resource->package->status : null,
            'sequence' => (int) $this->sequence,
            'document_class' => $this->document_class,
            'file_name' => $this->file_name,
            'mime_type' => $this->mime_type,
            'size' => (int) $this->size,
            'sha256' => $this->sha256,
            'document_type' => $this->document_type,
            'document_date' => $this->document_date?->toDateString(),
            'document_number' => $this->document_number,
            'counterpart_name' => $this->counterpart_name,
            'counterpart_vat_number' => $this->counterpart_vat_number,
            'own_vat_number' => $this->own_vat_number,
            'source_type' => $this->source_type,
            'source_id' => (string) $this->source_id,
        ];
    }
}
