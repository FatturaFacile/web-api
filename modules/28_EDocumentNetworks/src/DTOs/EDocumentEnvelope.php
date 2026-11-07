<?php

declare(strict_types=1);

namespace Modules\EDocumentNetworks\DTOs;

/**
 * A document ready for a network. This module builds no format: the payload comes already built.
 */
final readonly class EDocumentEnvelope
{
    public function __construct(
        public string $documentReference,
        public string $recipientIdentifier,
        public string $payload,
    ) {}
}
