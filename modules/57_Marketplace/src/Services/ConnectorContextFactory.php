<?php

declare(strict_types=1);

namespace Modules\Marketplace\Services;

use Modules\Marketplace\Models\MarketplaceInstallation;
use Modules\Marketplace\Support\ConnectorContext;

/**
 * Builds what a connector receives for one call: the settings and the decrypted credentials of an installation. The
 * context lives for the call and is never persisted, logged or returned.
 */
final class ConnectorContextFactory
{
    public function for(MarketplaceInstallation $installation): ConnectorContext
    {
        return new ConnectorContext(
            (string) $installation->tenant_id,
            $installation->id,
            $installation->connector_key,
            $installation->settings ?? [],
            array_map('strval', $installation->credentials ?? []),
        );
    }
}
