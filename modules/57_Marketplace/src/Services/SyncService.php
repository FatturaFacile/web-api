<?php

declare(strict_types=1);

namespace Modules\Marketplace\Services;

use App\Models\User;
use App\Support\Database\TransactionLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Modules\Marketplace\Contracts\Connector;
use Modules\Marketplace\Contracts\PullsRecords;
use Modules\Marketplace\Exceptions\ConnectorException;
use Modules\Marketplace\Exceptions\ConnectorRateLimitedException;
use Modules\Marketplace\Models\MarketplaceInstallation;
use Modules\Marketplace\Models\MarketplaceSyncRun;
use Modules\Marketplace\Support\ConnectorContext;
use Modules\Marketplace\Support\ConnectorRegistry;
use Modules\Marketplace\Support\RecordMapperRegistry;
use Modules\Marketplace\Support\SyncResult;
use Throwable;

/**
 * Runs the synchronisations of an installation and keeps the run log.
 *
 * - Only an enabled installation synchronises, only a resource of its connector.
 * - The rate limit of the installation (the lower limit of the company or the default of the connector) is a calls-a-minute
 *   budget: a run beyond it is recorded as `rate_limited` and refused with a retry delay, without calling the external
 *   system.
 * - One run of a resource at a time: a second start while the first is in progress is refused, unless the first is stale.
 * - The call to the external system is made outside any database transaction. A failure ends the run as `failed` with a
 *   message cleaned of the credentials; nothing secret is stored, logged or returned.
 */
final readonly class SyncService
{
    /**
     * Inject the collaborators.
     */
    public function __construct(
        private ConnectorRegistry $registry,
        private ConnectorContextFactory $contexts,
        private InstallationService $installations,
        private RecordMapperRegistry $mappers,
        private RecordImporter $importer,
    ) {}

    /**
     * @throws ValidationException|ConnectorRateLimitedException
     */
    public function run(MarketplaceInstallation $installation, string $resource, ?User $user = null, string $trigger = 'manual'): MarketplaceSyncRun
    {
        $connector = $this->registry->find($installation->connector_key)
            ?? throw ValidationException::withMessages(['connector_key' => [__('marketplace_errors.installation.unknown_connector')]]);
        $definition = $connector->definition();

        if (! $installation->isEnabled()) {
            throw ValidationException::withMessages(['status' => [__('marketplace_errors.sync.not_enabled')]]);
        }

        if (! in_array($resource, $definition->resources, true)) {
            throw ValidationException::withMessages(['resource' => [__('marketplace_errors.sync.unknown_resource', ['resource' => $resource])]]);
        }

        $limit = $this->installations->effectiveRateLimit($installation, $definition);
        $key = 'marketplace-sync:'.$installation->id;

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            $retry = RateLimiter::availableIn($key);
            $this->record($installation, $resource, $trigger, $user, MarketplaceSyncRun::RateLimited, __('marketplace_errors.sync.rate_limited', ['seconds' => $retry]));

            throw new ConnectorRateLimitedException($retry);
        }

        RateLimiter::hit($key, 60);
        $run = $this->start($installation, $resource, $trigger, $user);
        $context = $this->contexts->for($installation);

        try {
            $result = $this->execute($connector, $installation, $run, $context, $resource);
            $run->update([
                'status' => MarketplaceSyncRun::Succeeded,
                'finished_at' => now(),
                'records_processed' => $result->processed,
                'records_failed' => $result->failed,
                'message' => $result->message === null ? null : mb_substr($context->redact($result->message), 0, 500),
            ]);
            $installation->update(['last_sync_at' => now()]);
        } catch (ConnectorException $exception) {
            $run->update(['status' => MarketplaceSyncRun::Failed, 'finished_at' => now(), 'message' => mb_substr($context->redact($exception->getMessage()), 0, 500)]);
        } catch (Throwable $exception) {
            report($exception);
            $run->update(['status' => MarketplaceSyncRun::Failed, 'finished_at' => now(), 'message' => __('marketplace_errors.sync.failed')]);
        }

        return $run->refresh();
    }

    /**
     * Synchronises one resource. A connector that hands over its records ({@see PullsRecords}) and has a mapper for the
     * resource is pulled from the stored cursor (the only external call, outside any transaction), its records are mapped
     * into the staging table one by one and the cursor advances; any other connector runs its own sync.
     */
    private function execute(Connector $connector, MarketplaceInstallation $installation, MarketplaceSyncRun $run, ConnectorContext $context, string $resource): SyncResult
    {
        $mapper = $connector instanceof PullsRecords ? $this->mappers->find($installation->connector_key, $resource) : null;

        if (! $connector instanceof PullsRecords || $mapper === null) {
            return $connector->sync($context, $resource);
        }

        $cursors = $installation->sync_cursors ?? [];
        $records = $connector->pull($context, $resource, $cursors[$resource] ?? null);
        $outcome = $this->importer->import($installation, $run, $resource, $mapper, $records, $context);

        if ($outcome->cursor !== null) {
            $installation->update(['sync_cursors' => [...$cursors, $resource => $outcome->cursor]]);
        }

        return new SyncResult($outcome->processed, $outcome->failed, __('marketplace_errors.sync.fetched', ['count' => $outcome->processed, 'resource' => $resource]));
    }

    /**
     * @throws ValidationException when the resource is already being synchronised
     */
    private function start(MarketplaceInstallation $installation, string $resource, string $trigger, ?User $user): MarketplaceSyncRun
    {
        return DB::transaction(function () use ($installation, $resource, $trigger, $user): MarketplaceSyncRun {
            TransactionLock::acquire('marketplace-sync:'.$installation->id.':'.$resource);
            $stale = now()->subMinutes((int) config('marketplace.stale_run_minutes', 15));

            if (MarketplaceSyncRun::query()->where('installation_id', $installation->id)->where('resource', $resource)->where('status', MarketplaceSyncRun::Running)->where('started_at', '>', $stale)->exists()) {
                throw ValidationException::withMessages(['resource' => [__('marketplace_errors.sync.already_running')]]);
            }

            MarketplaceSyncRun::query()->where('installation_id', $installation->id)->where('resource', $resource)->where('status', MarketplaceSyncRun::Running)->where('started_at', '<=', $stale)
                ->update(['status' => MarketplaceSyncRun::Failed, 'finished_at' => now(), 'message' => __('marketplace_errors.sync.stale')]);

            return $this->record($installation, $resource, $trigger, $user, MarketplaceSyncRun::Running, null);
        });
    }

    private function record(MarketplaceInstallation $installation, string $resource, string $trigger, ?User $user, string $status, ?string $message): MarketplaceSyncRun
    {
        return MarketplaceSyncRun::create([
            'tenant_id' => $installation->tenant_id,
            'installation_id' => $installation->id,
            'resource' => $resource,
            'trigger' => $trigger,
            'status' => $status,
            'started_at' => now(),
            'finished_at' => $status === MarketplaceSyncRun::Running ? null : now(),
            'message' => $message,
            'triggered_by' => $user?->id,
        ]);
    }
}
