<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Application\DTOs;

/**
 * A document found by the collector, ready to be copied into a batch.
 */
final readonly class PreservationCandidate
{
    /**
     * @param  array<string, mixed>  $metadata  AgID metadata for the PdV.xml (see AgidPdvXmlBuilder)
     */
    public function __construct(
        public string $preservableType,
        public string $preservableId,
        public string $fileName,
        public string $mimeType,
        public string $content,
        public string $documentType,
        public ?string $documentNumber,
        public ?string $documentDate,
        public ?string $counterpartyName,
        public ?string $counterpartyTaxId,
        public array $metadata = [],
    ) {}
}
