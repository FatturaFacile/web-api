<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Application\Commands;

use Modules\OcrExtraction\Domain\Enums\DocumentCategory;
use Modules\OcrExtraction\Domain\Enums\SourceChannel;

/**
 * Puts a stored DMS document (Mod 007) in the AI Inbox queue.
 */
final readonly class EnqueueDocumentOcrExtractionCommand
{
    public function __construct(
        public string $tenantId,
        public string $dmsDocumentId,
        public SourceChannel $sourceChannel,
        public ?DocumentCategory $documentCategoryHint = null,
        public ?string $requestedByUserId = null,
    ) {}
}
