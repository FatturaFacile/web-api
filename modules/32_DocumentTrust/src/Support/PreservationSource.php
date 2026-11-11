<?php

declare(strict_types=1);

namespace Modules\DocumentTrust\Support;

/**
 * A document resolved for preservation: its bytes and the metadata the manifest records.
 */
final readonly class PreservationSource
{
    /**
     * @param  array<string, mixed>  $extra  further metadata kept in the item as it is
     */
    public function __construct(
        public string $sourceType,
        public string $sourceId,
        public string $fileName,
        public ?string $mimeType,
        public string $content,
        public ?string $documentType,
        public ?string $documentDate,
        public ?string $documentNumber,
        public ?string $counterpartName,
        public ?string $counterpartVatNumber,
        public ?string $ownVatNumber,
        public array $extra = [],
    ) {}
}
