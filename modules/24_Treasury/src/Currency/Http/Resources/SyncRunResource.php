<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Treasury\Currency\Infrastructure\Persistence\ExchangeRateSyncRun;

/**
 * @mixin ExchangeRateSyncRun
 */
final class SyncRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->resource->getKey(),
            'provider' => $this->resource->provider,
            'status' => $this->resource->status->value,
            'trigger' => $this->resource->trigger->value,
            'started_at' => $this->resource->started_at?->toIso8601String(),
            'finished_at' => $this->resource->finished_at?->toIso8601String(),
            'rates_count' => (int) $this->resource->rates_count,
            'derived_count' => (int) $this->resource->derived_count,
            'latest_rate_date' => $this->resource->latest_rate_date?->toDateString(),
            'error_message' => $this->resource->error_message,
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
