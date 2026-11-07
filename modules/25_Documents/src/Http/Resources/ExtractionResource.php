<?php

declare(strict_types=1);

namespace Modules\Documents\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ExtractionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'document_id' => (string) $this->document_id,
            'document_version_id' => (string) $this->document_version_id,
            'status' => $this->status->value,
            'driver' => $this->driver,
            'proposed_fields' => $this->proposed_fields ?? [],
            'confirmed_fields' => $this->confirmed_fields,
            'failure_reason' => $this->failure_reason,
            'decided_at' => $this->decided_at?->toIso8601String(),
        ];
    }
}
