<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Application\Handlers;

use Modules\DigitalPreservation\Application\Commands\UpdatePreservationConfigurationCommand;
use Modules\DigitalPreservation\Infrastructure\Models\PreservationConfiguration;
use Modules\DigitalPreservation\Infrastructure\Services\PreservationConfigurationResolver;

/**
 * Creates or updates the configuration of the tenant.
 */
final readonly class UpdatePreservationConfigurationHandler
{
    public function __construct(private PreservationConfigurationResolver $configurations) {}

    public function handle(UpdatePreservationConfigurationCommand $command): PreservationConfiguration
    {
        $configuration = $this->configurations->current();
        $configuration->fill($command->attributes);

        if ($command->credentials !== null) {
            $configuration->credentials = $command->credentials;
        }

        if ($command->tsaCredentials !== null) {
            $configuration->tsa_credentials = $command->tsaCredentials;
        }

        $configuration->save();

        return $configuration->refresh();
    }
}
