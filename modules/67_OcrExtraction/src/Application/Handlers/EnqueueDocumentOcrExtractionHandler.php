<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Application\Handlers;

use Enterprise\AuditLedger\Actions\RecordAuditAction;
use Modules\Documents\Models\DmsDocument;
use Modules\OcrExtraction\Application\Commands\EnqueueDocumentOcrExtractionCommand;
use Modules\OcrExtraction\Domain\Enums\DocumentCategory;
use Modules\OcrExtraction\Domain\Enums\ExtractionJobStatus;
use Modules\OcrExtraction\Domain\Exceptions\OcrExtractionNotFoundException;
use Modules\OcrExtraction\Infrastructure\Jobs\ProcessDocumentExtractionPipelineJob;
use Modules\OcrExtraction\Infrastructure\Models\DocumentExtractionJob;
use Modules\OcrExtraction\Infrastructure\Services\OcrTenantRunner;

/**
 * Creates the extraction job of a DMS document in Queued status and dispatches the pipeline on the `ai-ocr` queue. A
 * document that already has an unfinished job gets that job back, so a double upload event never extracts twice.
 */
final readonly class EnqueueDocumentOcrExtractionHandler
{
    public function __construct(private OcrTenantRunner $runner, private RecordAuditAction $audit) {}

    public function handle(EnqueueDocumentOcrExtractionCommand $command): DocumentExtractionJob
    {
        $job = $this->runner->run($command->tenantId, function () use ($command): DocumentExtractionJob {
            $document = DmsDocument::query()->where('tenant_id', $command->tenantId)->find($command->dmsDocumentId);

            if ($document === null) {
                throw new OcrExtractionNotFoundException('The DMS document does not exist.');
            }

            $active = DocumentExtractionJob::query()
                ->where('tenant_id', $command->tenantId)
                ->where('dms_document_id', $document->id)
                ->whereIn('status', [ExtractionJobStatus::Queued->value, ExtractionJobStatus::Processing->value, ExtractionJobStatus::ReadyForReview->value])
                ->first();

            if ($active !== null) {
                return $active;
            }

            $job = DocumentExtractionJob::create([
                'tenant_id' => $command->tenantId,
                'dms_document_id' => $document->id,
                'source_channel' => $command->sourceChannel,
                'document_category' => $command->documentCategoryHint ?? DocumentCategory::VendorInvoice,
                'status' => ExtractionJobStatus::Queued,
                'overall_confidence_score' => 0,
                'requested_by_user_id' => $command->requestedByUserId,
            ]);

            $this->audit->execute('OCR_EXTRACTION_ENQUEUED', $job, null, [
                'dms_document_id' => (string) $document->id,
                'source_channel' => $command->sourceChannel->value,
                'document_category' => $job->document_category->value,
            ], 'ocr_extraction');

            ProcessDocumentExtractionPipelineJob::dispatch($command->tenantId, (string) $job->id);

            return $job;
        });

        return $job->refresh();
    }
}
