<?php

declare(strict_types=1);

namespace Modules\BusinessIntelligence\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\BusinessIntelligence\Models\BiSnapshot;

/**
 * A snapshot. The stored figures (`payload`) are only loaded by the detail response, the list leaves the column out.
 *
 * @mixin BiSnapshot
 */
final class SnapshotResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'taken_by' => $this->taken_by,
            'taken_on' => $this->taken_on->toDateString(),
            'trigger' => $this->trigger,
            'status' => $this->status,
            'error' => $this->error,
            'row_count' => $this->row_count,
            'payload' => $this->when(array_key_exists('payload', $this->resource->getAttributes()), fn (): mixed => $this->payload),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
