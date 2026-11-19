<?php

declare(strict_types=1);

namespace App\Http\Resources\Audit;

use App\Models\AuditWormBatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AuditWormBatch
 */
class AuditWormBatchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'from_sequence' => $this->from_sequence,
            'to_sequence' => $this->to_sequence,
            'record_count' => $this->record_count,
            'merkle_root_hash' => $this->merkle_root_hash,
            'storage_disk' => $this->storage_disk,
            'storage_path' => $this->storage_path,
            'manifest_path' => $this->manifest_path,
            'retention_until' => $this->retention_until->toIso8601String(),
            'sealed_at' => $this->sealed_at->toIso8601String(),
        ];
    }
}
