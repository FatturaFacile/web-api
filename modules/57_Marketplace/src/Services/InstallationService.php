<?php

declare(strict_types=1);

namespace Modules\Marketplace\Services;

use App\Models\User;
use App\Support\Database\TransactionLock;
use Enterprise\MultiTenant\Services\ModuleAccessGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Marketplace\Contracts\Connector;
use Modules\Marketplace\Models\MarketplaceInstallation;
use Modules\Marketplace\Models\MarketplaceSyncRun;
use Modules\Marketplace\Support\ConnectorDefinition;
use Modules\Marketplace\Support\ConnectorRegistry;

/**
 * The lifecycle of an installation: install, configure, enable, disable, upgrade and uninstall.
 *
 * - An installation grants the connector the scopes it requires (`<module>.<read|write>`), and never more than the
 *   installing member can do themselves.
 * - It starts as `pending_configuration`; a valid configuration makes it `disabled`; enabling needs a configuration, the
 *   required scopes and a passing health check.
 * - A new version of the connector that needs a scope that was not granted, or a field that is not configured, stops the
 *   installation until it is upgraded or configured again.
 * - Uninstalling wipes the credentials, keeps the history of the runs and is refused while a run is in progress.
 */
final readonly class InstallationService
{
    /**
     * Inject the collaborators.
     */
    public function __construct(
        private ConnectorRegistry $registry,
        private ConfigurationValidator $configuration,
        private HealthService $health,
        private ModuleAccessGate $gate,
    ) {}

    /**
     * @param  list<string>  $grantedScopes
     *
     * @throws ValidationException
     */
    public function install(string $tenantId, User $user, string $connectorKey, string $name, array $grantedScopes): MarketplaceInstallation
    {
        $connector = $this->connector($connectorKey, 'connector_key');
        $definition = $connector->definition();
        $scopes = $this->assertScopes($definition, $user, $tenantId, $grantedScopes);

        return DB::transaction(function () use ($tenantId, $user, $definition, $name, $scopes): MarketplaceInstallation {
            TransactionLock::acquire('marketplace-install:'.$tenantId.':'.$definition->key);
            $limit = (int) config('marketplace.max_installations_per_connector', 5);

            if (MarketplaceInstallation::query()->where('connector_key', $definition->key)->count() >= $limit) {
                throw ValidationException::withMessages(['connector_key' => [__('marketplace_errors.installation.limit', ['max' => $limit])]]);
            }

            if (MarketplaceInstallation::query()->where('connector_key', $definition->key)->where('name', $name)->exists()) {
                throw ValidationException::withMessages(['name' => [__('marketplace_errors.installation.name_taken')]]);
            }

            return MarketplaceInstallation::create([
                'tenant_id' => $tenantId,
                'connector_key' => $definition->key,
                'connector_version' => $definition->version,
                'name' => $name,
                'status' => MarketplaceInstallation::PendingConfiguration,
                'granted_scopes' => $scopes,
                'installed_by' => $user->id,
            ]);
        });
    }

    /**
     * Saves the configuration. An enabled installation stays enabled, but its health is unknown until it is checked again.
     *
     * @param  array<string, mixed>  $config  field key => value
     * @param  array{rate_limit_per_minute?: int|null}  $options
     *
     * @throws ValidationException
     */
    public function configure(MarketplaceInstallation $installation, array $config, array $options = []): MarketplaceInstallation
    {
        $definition = $this->connector($installation->connector_key, 'connector_key')->definition();
        $clean = $this->configuration->validate($definition, $config, $installation->credentials ?? []);
        $fields = [
            'settings' => $clean['settings'],
            'credentials' => $clean['credentials'],
            'configured_at' => now(),
            'last_health_status' => 'unknown',
            'last_health_checked_at' => null,
            'last_health_message' => null,
        ];

        if (array_key_exists('rate_limit_per_minute', $options)) {
            $limit = $options['rate_limit_per_minute'];

            if ($limit !== null && $limit > $definition->maxRateLimitPerMinute) {
                throw ValidationException::withMessages(['rate_limit_per_minute' => [__('marketplace_errors.installation.rate_limit_above_max', ['max' => $definition->maxRateLimitPerMinute])]]);
            }

            $fields['rate_limit_per_minute'] = $limit;
        }

        if ($installation->status === MarketplaceInstallation::PendingConfiguration) {
            $fields['status'] = MarketplaceInstallation::Disabled;
            $fields['disabled_at'] = now();
        }

        $installation->update($fields);

        return $installation->refresh();
    }

    /**
     * @throws ValidationException
     */
    public function enable(MarketplaceInstallation $installation): MarketplaceInstallation
    {
        if ($installation->isEnabled()) {
            return $installation;
        }

        $definition = $this->connector($installation->connector_key, 'connector_key')->definition();

        if (! $installation->isConfigured() || $installation->status === MarketplaceInstallation::PendingConfiguration) {
            throw ValidationException::withMessages(['status' => [__('marketplace_errors.installation.not_configured')]]);
        }

        $missing = array_values(array_diff($definition->requiredScopes, $installation->granted_scopes));

        if ($missing !== []) {
            throw ValidationException::withMessages(['status' => [__('marketplace_errors.installation.scopes_missing', ['scopes' => implode(', ', $missing)])]]);
        }

        $check = $this->health->check($installation, 'enable');

        if ($check->status === 'unhealthy') {
            throw ValidationException::withMessages(['status' => [__('marketplace_errors.installation.unhealthy', ['message' => (string) $check->message])]]);
        }

        $installation->update(['status' => MarketplaceInstallation::Enabled, 'enabled_at' => now()]);

        return $installation->refresh();
    }

    public function disable(MarketplaceInstallation $installation): MarketplaceInstallation
    {
        if ($installation->status === MarketplaceInstallation::Enabled) {
            $installation->update(['status' => MarketplaceInstallation::Disabled, 'disabled_at' => now()]);
        }

        return $installation->refresh();
    }

    /**
     * Moves the installation to the version of the connector in the catalog. The scopes the new version requires must be
     * granted now; a configuration that no longer satisfies the schema sends the installation back to
     * `pending_configuration`, disabled.
     *
     * @param  list<string>  $grantedScopes
     *
     * @throws ValidationException
     */
    public function upgrade(MarketplaceInstallation $installation, User $user, array $grantedScopes): MarketplaceInstallation
    {
        $definition = $this->connector($installation->connector_key, 'connector_key')->definition();

        if (version_compare($definition->version, $installation->connector_version, '<=')) {
            throw ValidationException::withMessages(['connector_version' => [__('marketplace_errors.installation.up_to_date')]]);
        }

        $scopes = $this->assertScopes($definition, $user, (string) $installation->tenant_id, [...$installation->granted_scopes, ...$grantedScopes]);
        $complete = $this->configuration->isComplete($definition, $installation->settings ?? [], $installation->credentials ?? []);
        $fields = ['connector_version' => $definition->version, 'granted_scopes' => $scopes];

        if (! $complete) {
            $fields += ['status' => MarketplaceInstallation::PendingConfiguration, 'configured_at' => null, 'disabled_at' => now()];
        }

        $installation->update($fields);

        return $installation->refresh();
    }

    /**
     * @throws ValidationException when a run is in progress
     */
    public function uninstall(MarketplaceInstallation $installation): void
    {
        DB::transaction(function () use ($installation): void {
            TransactionLock::acquire('marketplace-installation:'.$installation->id);
            $running = MarketplaceSyncRun::query()->where('installation_id', $installation->id)->where('status', MarketplaceSyncRun::Running)
                ->where('started_at', '>', now()->subMinutes((int) config('marketplace.stale_run_minutes', 15)))->exists();

            if ($running) {
                throw ValidationException::withMessages(['id' => [__('marketplace_errors.installation.run_in_progress')]]);
            }

            $installation->update(['credentials' => null, 'settings' => null, 'status' => MarketplaceInstallation::Disabled, 'enabled_at' => null, 'configured_at' => null]);
            $installation->delete();
        });
    }

    /**
     * The catalog entry an installation is of, with whether a newer version exists.
     */
    public function definitionOf(MarketplaceInstallation $installation): ?ConnectorDefinition
    {
        return $this->registry->find($installation->connector_key)?->definition();
    }

    /**
     * Calls a connector can make a minute on this installation: the lower limit of the company, or the default of the
     * connector.
     */
    public function effectiveRateLimit(MarketplaceInstallation $installation, ConnectorDefinition $definition): int
    {
        return min($installation->rate_limit_per_minute ?? $definition->rateLimitPerMinute, $definition->maxRateLimitPerMinute);
    }

    /**
     * @throws ValidationException
     */
    private function connector(string $key, string $field): Connector
    {
        return $this->registry->find($key) ?? throw ValidationException::withMessages([$field => [__('marketplace_errors.installation.unknown_connector')]]);
    }

    /**
     * The scopes to grant: those the connector requires plus any further ones the installer asks for, all in the form of
     * a module and an access, all inside what the installer can do in the company.
     *
     * @param  list<string>  $requested
     * @return list<string>
     *
     * @throws ValidationException
     */
    private function assertScopes(ConnectorDefinition $definition, User $user, string $tenantId, array $requested): array
    {
        $requested = array_values(array_unique(array_map('strval', $requested)));
        $missing = array_values(array_diff($definition->requiredScopes, $requested));

        if ($missing !== []) {
            throw ValidationException::withMessages(['granted_scopes' => [__('marketplace_errors.installation.scopes_missing', ['scopes' => implode(', ', $missing)])]]);
        }

        foreach ($requested as $scope) {
            [$module, $access] = array_pad(explode('.', $scope, 2), 2, '');

            if (! in_array($access, ['read', 'write'], true) || config("permissions.modules.{$module}") === null || ! $this->gate->allows($user, $tenantId, $module, $access)) {
                throw ValidationException::withMessages(['granted_scopes' => [__('marketplace_errors.installation.scope_not_allowed', ['scope' => $scope])]]);
            }
        }

        return $requested;
    }
}
