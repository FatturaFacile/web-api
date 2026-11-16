<?php

declare(strict_types=1);

namespace Modules\Marketplace\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Marketplace\Models\MarketplaceInstallation;
use Modules\Marketplace\Services\InstallationService;

/**
 * An installation. The credentials are never part of it: `credentials_set` lists the keys that hold a value, nothing else.
 *
 * @mixin MarketplaceInstallation
 */
final class InstallationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $definition = app(InstallationService::class)->definitionOf($this->resource);

        return [
            'id' => $this->id,
            'connector_key' => $this->connector_key,
            'connector_name' => $definition?->name,
            'category' => $definition?->category,
            'connector_version' => $this->connector_version,
            'latest_version' => $definition?->version,
            'update_available' => $definition !== null && version_compare($definition->version, $this->connector_version, '>'),
            'name' => $this->name,
            'status' => $this->status,
            'settings' => $this->settings ?? (object) [],
            'credentials_set' => $this->credentialKeysSet(),
            'granted_scopes' => $this->granted_scopes,
            'rate_limit_per_minute' => $this->rate_limit_per_minute,
            'effective_rate_limit_per_minute' => $definition === null ? null : app(InstallationService::class)->effectiveRateLimit($this->resource, $definition),
            'configured_at' => $this->configured_at?->toIso8601String(),
            'enabled_at' => $this->enabled_at?->toIso8601String(),
            'disabled_at' => $this->disabled_at?->toIso8601String(),
            'health' => [
                'status' => $this->last_health_status,
                'checked_at' => $this->last_health_checked_at?->toIso8601String(),
                'message' => $this->last_health_message,
            ],
            'last_sync_at' => $this->last_sync_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
