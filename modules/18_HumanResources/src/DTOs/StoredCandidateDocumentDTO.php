<?php

declare(strict_types=1);

namespace Modules\HumanResources\DTOs;

/**
 * Reference of a candidate document stored on the private disk.
 */
final readonly class StoredCandidateDocumentDTO
{
    public function __construct(
        public string $storageKey,
        public string $originalName,
        public string $mimeType,
        public int $sizeBytes
    ) {}
}
