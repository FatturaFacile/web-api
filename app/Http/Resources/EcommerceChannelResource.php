<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\EcommerceChannel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EcommerceChannel
 */
class EcommerceChannelResource extends JsonResource
{
    private bool $revealSecret = false;

    /**
     * Include the webhook secret, which is shown once when the channel is created.
     */
    public function revealingSecret(): static
    {
        $this->revealSecret = true;

        return $this;
    }

    /**
     * Credentials never leave the API: only the names of the configured ones are listed.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return array_filter([
            'id' => $this->id,
            'name' => $this->name,
            'driver' => $this->driver,
            'api_url' => $this->api_url,
            'is_active' => $this->is_active,
            'sync_stock_enabled' => $this->sync_stock_enabled,
            'sync_orders_enabled' => $this->sync_orders_enabled,
            'last_sync_at' => $this->last_sync_at?->toIso8601String(),
            'settings' => $this->settings ?? [],
            'credential_keys' => array_keys($this->credentials ?? []),
            'webhook_url' => url("/api/v1/webhooks/ecommerce/{$this->id}"),
            'webhook_secret' => $this->revealSecret ? $this->webhook_secret : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ], fn (mixed $value, string $key): bool => $key !== 'webhook_secret' || $value !== null, ARRAY_FILTER_USE_BOTH);
    }
}
