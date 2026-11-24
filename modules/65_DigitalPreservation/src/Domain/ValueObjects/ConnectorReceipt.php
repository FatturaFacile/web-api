<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Domain\ValueObjects;

/**
 * What a connector returns after accepting a package: the provider reference and the receipt (RdV) XML.
 */
final readonly class ConnectorReceipt
{
    public function __construct(
        public string $externalId,
        public string $rdvXml,
        public bool $confirmed = false,
    ) {}
}
