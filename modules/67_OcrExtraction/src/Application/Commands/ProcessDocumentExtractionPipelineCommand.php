<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Application\Commands;

/**
 * Runs the extraction pipeline for one queued job (issued by the `ai-ocr` queue worker).
 */
final readonly class ProcessDocumentExtractionPipelineCommand
{
    public function __construct(public string $tenantId, public string $jobId) {}
}
