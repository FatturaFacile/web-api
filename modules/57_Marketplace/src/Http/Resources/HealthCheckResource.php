<?php

declare(strict_types=1);

namespace Modules\Marketplace\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Marketplace\Models\MarketplaceHealthCheck;

/**
 * A health check of an installation.
 *
 * @mixin MarketplaceHealthCheck
 */
final class HealthCheckResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'installation_id' => $this->installation_id,
            'status' => $this->status,
            'latency_ms' => $this->latency_ms,
            'message' => $this->message,
            'checked_at' => $this->checked_at->toIso8601String(),
        ];
    }
}
