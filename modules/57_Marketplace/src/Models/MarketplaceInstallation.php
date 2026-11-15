<?php

declare(strict_types=1);

namespace Modules\Marketplace\Models;

use Database\Factories\MarketplaceInstallationFactory;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A connector installed by a company. The settings (shop domain, environment) are stored as they are; the credentials
 * (tokens, keys, secrets) are encrypted at rest by the `encrypted:array` cast, hidden from serialisation and left out of
 * the audit ledger, so no response, log line or ledger entry ever carries them.
 *
 * Lifecycle: `pending_configuration` (installed, not yet configured) then `disabled` (configured) and `enabled`; an
 * uninstall soft-deletes the row after wiping the credentials.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $connector_key
 * @property string $connector_version
 * @property string $name
 * @property string $status
 * @property array<string, mixed>|null $settings
 * @property array<string, string>|null $credentials
 * @property list<string> $granted_scopes
 * @property int|null $rate_limit_per_minute
 * @property Carbon|null $configured_at
 * @property Carbon|null $enabled_at
 * @property Carbon|null $disabled_at
 * @property string $last_health_status
 * @property Carbon|null $last_health_checked_at
 * @property string|null $last_health_message
 * @property Carbon|null $last_sync_at
 * @property string|null $installed_by
 */
#[UseFactory(MarketplaceInstallationFactory::class)]
final class MarketplaceInstallation extends BaseEnterpriseModel
{
    public const string PendingConfiguration = 'pending_configuration';

    public const string Disabled = 'disabled';

    public const string Enabled = 'enabled';

    /** @var list<string> */
    public const array Statuses = [self::PendingConfiguration, self::Disabled, self::Enabled];

    protected $table = 'marketplace_installations';

    /**
     * @var list<string>
     */
    protected $hidden = ['credentials'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'credentials' => 'encrypted:array',
            'granted_scopes' => 'array',
            'rate_limit_per_minute' => 'integer',
            'configured_at' => 'datetime',
            'enabled_at' => 'datetime',
            'disabled_at' => 'datetime',
            'last_health_checked_at' => 'datetime',
            'last_sync_at' => 'datetime',
        ];
    }

    /**
     * The audit ledger records that credentials changed, never what they are.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function auditPayload(array $attributes): array
    {
        if (array_key_exists('credentials', $attributes)) {
            $attributes['credentials'] = '[encrypted]';
        }

        return $attributes;
    }

    public function isConfigured(): bool
    {
        return $this->configured_at !== null;
    }

    public function isEnabled(): bool
    {
        return $this->status === self::Enabled;
    }

    /**
     * Keys of the credentials that hold a value, for the screens that show "set" instead of the secret.
     *
     * @return list<string>
     */
    public function credentialKeysSet(): array
    {
        return array_keys(array_filter($this->credentials ?? [], static fn (mixed $value): bool => is_string($value) && $value !== ''));
    }

    /**
     * @return HasMany<MarketplaceSyncRun, $this>
     */
    public function syncRuns(): HasMany
    {
        return $this->hasMany(MarketplaceSyncRun::class, 'installation_id')->orderByDesc('started_at');
    }

    /**
     * @return HasMany<MarketplaceHealthCheck, $this>
     */
    public function healthChecks(): HasMany
    {
        return $this->hasMany(MarketplaceHealthCheck::class, 'installation_id')->orderByDesc('checked_at');
    }
}
