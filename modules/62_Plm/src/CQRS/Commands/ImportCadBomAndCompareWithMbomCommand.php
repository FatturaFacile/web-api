<?php

declare(strict_types=1);

namespace Modules\Plm\CQRS\Commands;

/**
 * Ingests a CAD export (XML/CSV/STEP or structured part list), optionally creates missing part codes
 * and CAD attachments (PDF/DXF/STEP/STL), and computes a side-by-side eBOM vs. mBOM diff with cost roll-up.
 */
final readonly class ImportCadBomAndCompareWithMbomCommand
{
    /**
     * @param  list<array<string, mixed>>|null  $parts
     * @param  array<string, mixed>  $sourceFileMetadata
     * @param  list<array<string, mixed>>  $cadAttachments
     */
    public function __construct(
        public string $tenantId,
        public ?string $userId,
        public string $cadSourceSystem,
        public string $productCode,
        public string $revisionCode = 'A',
        public ?string $rawPayload = null,
        public ?string $payloadFormat = null,
        public ?array $parts = null,
        public array $sourceFileMetadata = [],
        public array $cadAttachments = [],
        public bool $autoCreateMissingParts = true,
        public bool $commitToMbom = false,
        public ?string $bomName = null,
    ) {}
}
