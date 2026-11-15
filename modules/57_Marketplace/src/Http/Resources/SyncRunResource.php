<?php

declare(strict_types=1);

namespace Modules\Marketplace\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Marketplace\Models\MarketplaceSyncRun;

/**
 * A run of the sync log.
 *
 * @mixin MarketplaceSyncRun
 */
final class SyncRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'installation_id' => $this->installation_id,
            'installation_name' => $this->whenLoaded('installation', fn (): ?string => $this->installation?->name),
            'resource' => $this->resource,
            'trigger' => $this->trigger,
            'status' => $this->status,
            'started_at' => $this->started_at->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'records_processed' => $this->records_processed,
            'records_failed' => $this->records_failed,
            'message' => $this->message,
        ];
    }
}
