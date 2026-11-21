<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Application\Handlers;

use App\Models\Tenant;
use Enterprise\AuditLedger\Actions\RecordAuditAction;
use Illuminate\Support\Facades\DB;
use Modules\Documents\Models\DmsDocument;
use Modules\Documents\Models\DmsDocumentLink;
use Modules\OcrExtraction\Application\Commands\ConfirmAndConvertExtractedDocumentCommand;
use Modules\OcrExtraction\Application\Commands\TrainVendorCodingFeedbackCommand;
use Modules\OcrExtraction\Domain\Enums\DocumentCategory;
use Modules\OcrExtraction\Domain\Enums\ExtractionJobStatus;
use Modules\OcrExtraction\Domain\Exceptions\OcrExtractionException;
use Modules\OcrExtraction\Domain\Exceptions\OcrExtractionNotFoundException;
use Modules\OcrExtraction\Infrastructure\Models\DocumentExtractionJob;
use Modules\OcrExtraction\Infrastructure\Services\ExtractedDocumentConverter;
use Modules\OcrExtraction\Infrastructure\Services\OcrTenantRunner;
use Modules\OcrExtraction\Infrastructure\Services\ReviewedDocumentValidator;

/**
 * Confirms a reviewed document and converts it, atomically: the rules are checked, the business document is created
 * through the owning module, the vendor coding learns from the accountant's choices and the job is closed with its
 * audit entry, all in one transaction. The original DMS file is then linked to the new record and marked as a
 * preservation candidate (Mod 007 / Mod 212).
 */
final readonly class ConfirmAndConvertExtractedDocumentHandler
{
    /**
     * DMS link alias of the business documents that have one.
     *
     * @var array<string, string>
     */
    private const array LinkAliases = ['supplier_invoice' => 'supplier_invoice', 'expense' => 'expense'];

    public function __construct(
        private OcrTenantRunner $runner,
        private ReviewedDocumentValidator $validator,
        private ExtractedDocumentConverter $converter,
        private TrainVendorCodingFeedbackHandler $feedback,
        private RecordAuditAction $audit,
    ) {}

    public function handle(ConfirmAndConvertExtractedDocumentCommand $command): DocumentExtractionJob
    {
        return $this->runner->run($command->tenantId, function (Tenant $tenant) use ($command): DocumentExtractionJob {
            $job = DocumentExtractionJob::query()->where('tenant_id', $command->tenantId)->find($command->jobId);

            if ($job === null) {
                throw new OcrExtractionNotFoundException('The extraction job does not exist.');
            }

            if ($job->status !== ExtractionJobStatus::ReadyForReview) {
                throw new OcrExtractionException('Only a document ready for review can be confirmed.');
            }

            $reviewed = $command->reviewedPayload;
            $category = DocumentCategory::tryFrom((string) ($reviewed['category'] ?? '')) ?? $job->document_category;
            $document = $this->validator->validate($command->tenantId, strtoupper((string) $tenant->country), $category, $reviewed, $job->vendor_id);

            $created = DB::transaction(function () use ($command, $job, $category, $document, $reviewed): array {
                $created = $this->converter->convert($job, $category, $document, $reviewed, $command->userId);
                $before = $job->extracted_payload_json ?? [];

                $job->update([
                    'document_category' => $category,
                    'vendor_id' => $document['vendor_id'],
                    'status' => ExtractionJobStatus::Confirmed,
                    'confirmed_by_user_id' => $command->userId,
                    'confirmed_at' => now(),
                    'resulting_entity_type' => $created['type'],
                    'resulting_entity_id' => $created['id'],
                    'matched_purchase_order_ids_json' => $document['purchase_order_ids'],
                    'matched_goods_receipt_ids_json' => array_values(array_unique(array_filter((array) ($reviewed['goods_receipt_ids'] ?? []), 'is_string'))),
                    'review_draft_json' => null,
                ]);

                $this->train($command->tenantId, $document, $before);

                $this->audit->execute('OCR_EXTRACTION_CONFIRMED', $job, $this->snapshot($before), [
                    'resulting_entity_type' => $created['type'],
                    'resulting_entity_id' => $created['id'],
                    'confirmed_by_user_id' => $command->userId,
                    'document_category' => $category->value,
                    'corrected_fields' => $this->corrections($before, $document),
                    'match_override_reason' => $reviewed['match_override_reason'] ?? null,
                ], 'ocr_extraction');

                return $created;
            });

            $this->archive($job, $created, $command->userId);

            return $job->refresh();
        });
    }

    /**
     * Teaches the vendor coding model, for the document kinds that carry accounting coding.
     *
     * @param  array{lines: list<array<string, mixed>>, vendor_id: string|null}  $document
     * @param  array<string, mixed>  $before
     */
    private function train(string $tenantId, array $document, array $before): void
    {
        if ($document['vendor_id'] === null) {
            return;
        }

        $original = (array) ($before['lines'] ?? []);
        $suggestions = [];
        $confirmed = [];

        foreach ($document['lines'] as $line) {
            if ($line['gl_account_id'] === null || $line['vat_code_id'] === null) {
                continue;
            }

            $source = (array) ($original[$line['source_line_index']] ?? []);
            $suggestions[] = [
                'prediction_model_id' => $source['prediction_model_id'] ?? null,
                'gl_account_id' => $source['suggested_gl_account_id'] ?? null,
                'vat_code_id' => $source['suggested_vat_code_id'] ?? null,
                'cost_center_id' => $source['suggested_cost_center_id'] ?? null,
            ];
            $confirmed[] = ['description' => $line['description'], 'gl_account_id' => $line['gl_account_id'], 'vat_code_id' => $line['vat_code_id'], 'cost_center_id' => $line['cost_center_id']];
        }

        if ($confirmed !== []) {
            $this->feedback->handle(new TrainVendorCodingFeedbackCommand($tenantId, $document['vendor_id'], $suggestions, $confirmed));
        }
    }

    /**
     * Fields the accountant changed against what was extracted or suggested (for the audit trail).
     *
     * @param  array<string, mixed>  $before
     * @param  array{header: array<string, mixed>, lines: list<array<string, mixed>>, totals: array{net: float, vat: float, gross: float}, vendor_id: string|null}  $document
     * @return list<string>
     */
    private function corrections(array $before, array $document): array
    {
        $changed = [];

        foreach (['number' => 'header.document_number', 'issue_date' => 'header.issue_date', 'due_date' => 'header.due_date', 'currency' => 'header.currency'] as $key => $field) {
            if (($before['document'][$key] ?? null) !== ($document['header'][$key] ?? null)) {
                $changed[] = $field;
            }
        }

        foreach (['net', 'vat', 'gross'] as $key) {
            if (round((float) ($before['totals'][$key] ?? 0), 2) !== round($document['totals'][$key], 2)) {
                $changed[] = 'totals.'.$key;
            }
        }

        foreach ($document['lines'] as $index => $line) {
            $source = (array) (($before['lines'] ?? [])[$line['source_line_index']] ?? []);

            foreach (['gl_account_id' => 'suggested_gl_account_id', 'vat_code_id' => 'suggested_vat_code_id', 'cost_center_id' => 'suggested_cost_center_id'] as $key => $suggestion) {
                if (($source[$suggestion] ?? null) !== $line[$key]) {
                    $changed[] = "lines[{$index}].{$key}";
                }
            }

            foreach (['description', 'quantity', 'unit_price', 'net_amount'] as $key) {
                if (isset($source[$key]) && (string) $source[$key] !== (string) $line[$key] && (! is_numeric($source[$key]) || round((float) $source[$key], 4) !== round((float) $line[$key], 4))) {
                    $changed[] = "lines[{$index}].{$key}";
                }
            }
        }

        return $changed;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function snapshot(array $payload): array
    {
        return ['vendor' => $payload['vendor'] ?? null, 'document' => $payload['document'] ?? null, 'totals' => $payload['totals'] ?? null, 'line_count' => count((array) ($payload['lines'] ?? []))];
    }

    /**
     * Links the original file to the new record and marks it for preservation (the sealing itself is the preservation
     * package run of Mod 212). A failure here never undoes the posted document.
     *
     * @param  array{type: string, id: string}  $created
     */
    private function archive(DocumentExtractionJob $job, array $created, ?string $userId): void
    {
        $document = DmsDocument::query()->where('tenant_id', $job->tenant_id)->find($job->dms_document_id);

        if ($document === null) {
            return;
        }

        if (isset(self::LinkAliases[$created['type']])) {
            DmsDocumentLink::firstOrCreate(
                ['tenant_id' => $document->tenant_id, 'document_id' => $document->id, 'linkable_type' => self::LinkAliases[$created['type']], 'linkable_id' => $created['id']],
                ['created_by' => $userId],
            );
        }

        $metadata = $document->metadata ?? [];
        $metadata['ocr_extraction'] = ['job_id' => (string) $job->id, 'resulting_entity_type' => $created['type'], 'resulting_entity_id' => $created['id'], 'preservation_candidate' => true];
        $document->update(['document_type' => $job->document_category->value, 'metadata' => $metadata]);
    }
}
