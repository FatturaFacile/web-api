<?php

declare(strict_types=1);

namespace Modules\Documents\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Documents\Services\DocumentOcrPipeline;

/**
 * Queued job: run one OCR job of the DMS for its tenant.
 */
final class RunDocumentOcrJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Inject the collaborators.
     */
    public function __construct(public readonly string $ocrJobId, public readonly string $tenantId)
    {
        $this->afterCommit();
    }

    /**
     * Run the unit of work.
     */
    public function handle(DocumentOcrPipeline $pipeline): void
    {
        $pipeline->processFor($this->ocrJobId, $this->tenantId);
    }
}
