<?php

declare(strict_types=1);

namespace App\Http\Resources\Audit;

use Enterprise\AuditLedger\Models\AuditCheckpoint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property AuditCheckpoint $resource
 */
final class AuditCheckpointResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $checkpoint = $this->resource;

        return [
            'id' => $checkpoint->id,
            'type' => $checkpoint->type,
            'period_ref' => $checkpoint->period_ref,
            'sequence' => $checkpoint->sequence,
            'hash' => $checkpoint->hash,
            'signature' => $checkpoint->signature,
            'key_version' => $checkpoint->key_version,
            'created_at' => $checkpoint->created_at->toIso8601String(),
            'anchored_at' => $checkpoint->anchored_at?->toIso8601String(),
            'anchor_uri' => $checkpoint->anchor_uri,
        ];
    }
}
