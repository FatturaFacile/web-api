<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Infrastructure\Connectors;

use Illuminate\Contracts\Container\Container;
use Modules\DigitalPreservation\Domain\Contracts\PreservationConnectorInterface;
use Modules\DigitalPreservation\Domain\Enums\PreservationProvider;

/**
 * Resolves the connector of a provider from the container, so tests and deployments can rebind any of them.
 */
final readonly class PreservationConnectorManager
{
    public function __construct(private Container $container) {}

    public function for(PreservationProvider $provider): PreservationConnectorInterface
    {
        return $this->container->make(match ($provider) {
            PreservationProvider::LocalWorm => LocalWormVaultConnector::class,
            PreservationProvider::ArubaPec => ArubaPecConnector::class,
            PreservationProvider::InfoCert => InfoCertConnector::class,
            PreservationProvider::Namirial => NamirialConnector::class,
            PreservationProvider::Zucchetti => ZucchettiConnector::class,
        });
    }
}
