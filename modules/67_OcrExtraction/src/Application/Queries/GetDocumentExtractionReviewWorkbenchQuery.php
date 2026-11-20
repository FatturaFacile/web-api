<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Application\Queries;

use Modules\OcrExtraction\Domain\Enums\ExtractionJobStatus;

/**
 * The AI Inbox queue with its counters and, for a selected job, the full review workbench.
 */
final readonly class GetDocumentExtractionReviewWorkbenchQuery
{
    public function __construct(
        public string $tenantId,
        public ?string $jobId = null,
        public ?ExtractionJobStatus $status = null,
        public int $page = 1,
        public int $perPage = 25,
    ) {}
}
