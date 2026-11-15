<?php

declare(strict_types=1);

namespace Modules\Marketplace\Contracts;

use Modules\Marketplace\Support\ConnectorContext;
use Modules\Marketplace\Support\ConnectorDefinition;
use Modules\Marketplace\Support\HealthCheckResult;
use Modules\Marketplace\Support\SyncResult;

/**
 * The contract of a marketplace connector. A module or a package plugs a real connector in by implementing it and
 * registering the instance in the {@see \Modules\Marketplace\Support\ConnectorRegistry} from its service provider; the
 * marketplace then handles the catalog entry, the installation lifecycle, the encrypted credentials, the rate limit, the
 * health checks and the sync run log for it.
 *
 * An implementation never logs the credentials of the context and never puts them in an exception message; when a call to
 * the external system fails it throws a {@see \Modules\Marketplace\Exceptions\ConnectorException}.
 */
interface Connector
{
    /**
     * What the catalog shows: key, version, category, configuration schema, required scopes, resources and rate limit.
     */
    public function definition(): ConnectorDefinition;

    /**
     * Checks that the external system answers and accepts the credentials. It reports problems as an unhealthy or degraded
     * result instead of throwing.
     */
    public function checkHealth(ConnectorContext $context): HealthCheckResult;

    /**
     * Synchronises one of the resources of the definition.
     *
     * @throws \Modules\Marketplace\Exceptions\ConnectorException when the external system refuses or fails
     */
    public function sync(ConnectorContext $context, string $resource): SyncResult;
}
