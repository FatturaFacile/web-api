<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Infrastructure\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\OcrExtraction\Application\Commands\ProcessDocumentExtractionPipelineCommand;
use Modules\OcrExtraction\Application\Handlers\ProcessDocumentExtractionPipelineHandler;

/**
 * Queued job on the dedicated `ai-ocr` queue: runs the extraction pipeline of one job inside its tenant. The ids are
 * plain strings, so the payload never carries a model and a worker always re-reads the tenant-scoped row.
 */
final class ProcessDocumentExtractionPipelineJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly string $tenantId, public readonly string $jobId)
    {
        $this->onQueue((string) config('ocr_extraction.queue', 'ai-ocr'));
        $this->afterCommit();
    }

    public function handle(ProcessDocumentExtractionPipelineHandler $handler): void
    {
        $handler->handle(new ProcessDocumentExtractionPipelineCommand($this->tenantId, $this->jobId));
    }
}
