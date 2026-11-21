<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Application\Handlers;

use Enterprise\AuditLedger\Actions\RecordAuditAction;
use Modules\OcrExtraction\Domain\Enums\ExtractionJobStatus;
use Modules\OcrExtraction\Domain\Exceptions\OcrExtractionException;
use Modules\OcrExtraction\Domain\Exceptions\OcrExtractionNotFoundException;
use Modules\OcrExtraction\Infrastructure\Jobs\ProcessDocumentExtractionPipelineJob;
use Modules\OcrExtraction\Infrastructure\Models\DocumentExtractionJob;
use Modules\OcrExtraction\Infrastructure\Services\OcrTenantRunner;

/**
 * The other review actions of the workbench: save a draft of the accountant's edits, reject a document as invalid, and
 * re-run the extraction. Each is recorded in the audit trail.
 */
final readonly class ManageExtractionJobHandler
{
    public function __construct(private OcrTenantRunner $runner, private RecordAuditAction $audit) {}

    /**
     * Stores the edits so far without converting anything.
     *
     * @param  array<string, mixed>  $draft
     */
    public function saveDraft(string $tenantId, string $jobId, array $draft): DocumentExtractionJob
    {
        return $this->runner->run($tenantId, function () use ($tenantId, $jobId, $draft): DocumentExtractionJob {
            $job = $this->find($tenantId, $jobId);
            $this->assertStatus($job, [ExtractionJobStatus::ReadyForReview], 'Only a document ready for review can be edited.');

            $job->update(['review_draft_json' => $draft]);
            $this->audit->execute('OCR_EXTRACTION_DRAFT_SAVED', $job, null, ['fields' => array_keys($draft)], 'ocr_extraction');

            return $job->refresh();
        });
    }

    /**
     * Marks the document invalid: nothing is created and the job leaves the inbox.
     */
    public function reject(string $tenantId, string $jobId, ?string $userId, string $reason): DocumentExtractionJob
    {
        return $this->runner->run($tenantId, function () use ($tenantId, $jobId, $userId, $reason): DocumentExtractionJob {
            $job = $this->find($tenantId, $jobId);
            $this->assertStatus($job, [ExtractionJobStatus::ReadyForReview, ExtractionJobStatus::Failed], 'Only a document waiting in the inbox can be rejected.');

            $job->update(['status' => ExtractionJobStatus::Rejected, 'failure_reason' => $reason, 'confirmed_by_user_id' => $userId, 'confirmed_at' => now(), 'review_draft_json' => null]);
            $this->audit->execute('OCR_EXTRACTION_REJECTED', $job, null, ['reason' => $reason, 'rejected_by_user_id' => $userId], 'ocr_extraction');

            return $job->refresh();
        });
    }

    /**
     * Runs the extraction again (for example after the tenant changed its OCR engine), dropping the previous result and the draft.
     */
    public function rerun(string $tenantId, string $jobId, ?string $userId): DocumentExtractionJob
    {
        return $this->runner->run($tenantId, function () use ($tenantId, $jobId, $userId): DocumentExtractionJob {
            $job = $this->find($tenantId, $jobId);
            $this->assertStatus($job, [ExtractionJobStatus::ReadyForReview, ExtractionJobStatus::Failed], 'Only a document waiting in the inbox can be extracted again.');

            $job->update([
                'status' => ExtractionJobStatus::Queued,
                'extraction_method' => null,
                'ocr_provider_used' => null,
                'overall_confidence_score' => 0,
                'llm_tokens_used' => 0,
                'extracted_payload_json' => null,
                'bounding_boxes_json' => null,
                'review_draft_json' => null,
                'matched_purchase_order_ids_json' => null,
                'matched_goods_receipt_ids_json' => null,
                'failure_reason' => null,
            ]);
            $this->audit->execute('OCR_EXTRACTION_RERUN', $job, null, ['requested_by_user_id' => $userId], 'ocr_extraction');

            ProcessDocumentExtractionPipelineJob::dispatch($tenantId, (string) $job->id);

            return $job->refresh();
        });
    }

    private function find(string $tenantId, string $jobId): DocumentExtractionJob
    {
        $job = DocumentExtractionJob::query()->where('tenant_id', $tenantId)->find($jobId);

        if ($job === null) {
            throw new OcrExtractionNotFoundException('The extraction job does not exist.');
        }

        return $job;
    }

    /**
     * @param  list<ExtractionJobStatus>  $allowed
     */
    private function assertStatus(DocumentExtractionJob $job, array $allowed, string $message): void
    {
        if (! in_array($job->status, $allowed, true)) {
            throw new OcrExtractionException($message);
        }
    }
}
