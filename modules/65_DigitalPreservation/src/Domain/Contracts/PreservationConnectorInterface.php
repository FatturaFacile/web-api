<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Domain\Contracts;

use Modules\DigitalPreservation\Domain\Enums\PreservationProvider;
use Modules\DigitalPreservation\Domain\Exceptions\ConnectorException;
use Modules\DigitalPreservation\Domain\ValueObjects\ConnectorReceipt;
use Modules\DigitalPreservation\Infrastructure\Models\PreservationBatch;
use Modules\DigitalPreservation\Infrastructure\Models\PreservationConfiguration;

/**
 * Strategy of a preservation provider: the built-in WORM vault or an accredited conservator.
 */
interface PreservationConnectorInterface
{
    public function provider(): PreservationProvider;

    /**
     * Hand the PdV and its files over and return the receipt (RdV).
     *
     * @param  array<string, string>  $files  archive path => binary content
     *
     * @throws ConnectorException when the provider refuses or cannot be reached
     */
    public function dispatch(PreservationBatch $batch, string $pdvXml, array $files, PreservationConfiguration $configuration): ConnectorReceipt;

    /**
     * Check that the configured credentials and endpoint work.
     */
    public function testConnection(PreservationConfiguration $configuration): bool;
}
