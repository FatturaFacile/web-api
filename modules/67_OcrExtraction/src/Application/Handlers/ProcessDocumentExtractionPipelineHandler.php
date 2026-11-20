<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Application\Handlers;

use Enterprise\AuditLedger\Actions\RecordAuditAction;
use Modules\OcrExtraction\Application\Commands\ConfirmAndConvertExtractedDocumentCommand;
use Modules\OcrExtraction\Application\Commands\PredictAccountingCodingForLinesCommand;
use Modules\OcrExtraction\Application\Commands\ProcessDocumentExtractionPipelineCommand;
use Modules\OcrExtraction\Domain\Enums\DocumentCategory;
use Modules\OcrExtraction\Domain\Enums\ExtractionJobStatus;
use Modules\OcrExtraction\Domain\Enums\ExtractionMethod;
use Modules\OcrExtraction\Domain\Enums\OcrProviderType;
use Modules\OcrExtraction\Domain\Exceptions\OcrExtractionException;
use Modules\OcrExtraction\Domain\Exceptions\OcrExtractionNotFoundException;
use Modules\OcrExtraction\Domain\Exceptions\OcrProviderException;
use Modules\OcrExtraction\Domain\ValueObjects\BoundingBoxDto;
use Modules\OcrExtraction\Domain\ValueObjects\ExtractedPayloadDto;
use Modules\OcrExtraction\Domain\ValueObjects\OcrExtractionRequest;
use Modules\OcrExtraction\Infrastructure\Events\DocumentExtractionReadyBroadcast;
use Modules\OcrExtraction\Infrastructure\Extraction\FatturaPaExtractor;
use Modules\OcrExtraction\Infrastructure\Extraction\SwissQrBillExtractor;
use Modules\OcrExtraction\Infrastructure\Extraction\ZugferdExtractor;
use Modules\OcrExtraction\Infrastructure\Models\DocumentExtractionJob;
use Modules\OcrExtraction\Infrastructure\Models\TenantOcrConfiguration;
use Modules\OcrExtraction\Infrastructure\Services\DmsSourceReader;
use Modules\OcrExtraction\Infrastructure\Services\OcrProviderRegistry;
use Modules\OcrExtraction\Infrastructure\Services\OcrTenantRunner;
use Modules\OcrExtraction\Infrastructure\Services\ThreeWayMatchDiscovery;
use Modules\OcrExtraction\Infrastructure\Services\VendorResolver;
use Throwable;

/**
 * The extraction pipeline of one job, deterministic first:
 *  A. inspect the file: FatturaPA XML or a ZUGFeRD / Factur-X attachment are mapped exactly (no OCR, no tokens); a Swiss
 *     QR-bill fixes the payment fields exactly and the OCR engine reads only the rest; anything else is read in full;
 *  B. resolve the vendor (UID, VAT number, IBAN) and suggest the accounting coding of the lines;
 *  C. discover the open purchase orders and goods receipts of the vendor and measure the variances;
 *  D. mark the job ready for review and announce it in real time.
 * A failure at any step leaves the job Failed with the reason; the engine runs outside any database transaction.
 */
final readonly class ProcessDocumentExtractionPipelineHandler
{
    public function __construct(
        private OcrTenantRunner $runner,
        private DmsSourceReader $reader,
        private FatturaPaExtractor $fatturaPa,
        private ZugferdExtractor $zugferd,
        private SwissQrBillExtractor $qrBill,
        private OcrProviderRegistry $providers,
        private VendorResolver $vendors,
        private PredictAccountingCodingForLinesHandler $coding,
        private ThreeWayMatchDiscovery $matching,
        private ConfirmAndConvertExtractedDocumentHandler $confirm,
        private RecordAuditAction $audit,
    ) {}

    public function handle(ProcessDocumentExtractionPipelineCommand $command): DocumentExtractionJob
    {
        return $this->runner->run($command->tenantId, function () use ($command): DocumentExtractionJob {
            $job = DocumentExtractionJob::query()->where('tenant_id', $command->tenantId)->find($command->jobId);

            if ($job === null) {
                throw new OcrExtractionNotFoundException('The extraction job does not exist.');
            }

            if ($job->status !== ExtractionJobStatus::Queued) {
                return $job;
            }

            $job->update(['status' => ExtractionJobStatus::Processing, 'failure_reason' => null]);

            try {
                $this->process($job);
            } catch (OcrExtractionException $exception) {
                $this->fail($job, $exception->getMessage());
            } catch (Throwable $exception) {
                report($exception);
                $this->fail($job, 'The extraction failed unexpectedly.');
            }

            return $job->refresh();
        });
    }

    private function process(DocumentExtractionJob $job): void
    {
        $tenantId = (string) $job->tenant_id;
        $file = $this->reader->read((string) $job->dms_document_id);

        // Step A: deterministic inspection, then the OCR engine only where needed.
        $outcome = $this->extract($job, $file['contents'], $file['mime_type']);
        $payload = $outcome['payload']->toArray();
        $payload['meta'] = $outcome['meta'];

        $job->update([
            'extraction_method' => $outcome['method'],
            'ocr_provider_used' => $outcome['provider'],
            'llm_tokens_used' => $outcome['tokens'],
            'overall_confidence_score' => $outcome['payload']->overallConfidence(),
            'extracted_payload_json' => $payload,
            'bounding_boxes_json' => array_map(static fn (BoundingBoxDto $box): array => $box->toArray(), $outcome['boxes']),
        ]);

        // Step B: vendor resolution and accounting coding.
        $vendorId = $this->vendors->resolve($tenantId, $outcome['payload']);
        $job->update(['vendor_id' => $vendorId]);

        if ($vendorId !== null) {
            $this->coding->handle(new PredictAccountingCodingForLinesCommand((string) $job->id, $tenantId, $vendorId));
            $job->refresh();
        }

        // Step C: three-way matching discovery.
        if ($vendorId !== null && in_array($job->document_category, [DocumentCategory::VendorInvoice, DocumentCategory::TransportDdt], true)) {
            $match = $this->matching->discover($tenantId, $vendorId, $outcome['payload']);
            $payload = $job->extracted_payload_json ?? [];
            $payload['three_way_match'] = $match['evaluations'];
            $job->update([
                'extracted_payload_json' => $payload,
                'matched_purchase_order_ids_json' => $match['purchase_order_ids'],
                'matched_goods_receipt_ids_json' => $match['goods_receipt_ids'],
            ]);
        }

        // Step D: ready for review.
        $job->update(['status' => ExtractionJobStatus::ReadyForReview]);
        $this->audit->execute('OCR_EXTRACTION_COMPLETED', $job, null, [
            'extraction_method' => $outcome['method']->value,
            'ocr_provider_used' => $outcome['provider']?->value,
            'overall_confidence_score' => (float) $job->overall_confidence_score,
            'llm_tokens_used' => $outcome['tokens'],
            'vendor_id' => $vendorId,
        ], 'ocr_extraction');

        $this->announce($job);
        $this->autoConfirm($job);
    }

    /**
     * @return array{payload: ExtractedPayloadDto, boxes: array<string, BoundingBoxDto>, method: ExtractionMethod, provider: OcrProviderType|null, tokens: int, meta: array<string, mixed>}
     *
     * @throws OcrExtractionException when nothing can read the document
     */
    private function extract(DocumentExtractionJob $job, string $contents, string $mimeType): array
    {
        $isXml = in_array($mimeType, ['application/xml', 'text/xml'], true);

        if ($isXml) {
            $fatturaPa = $this->fatturaPa->looksLikeFatturaPa($contents) ? $this->fatturaPa->extract($contents) : null;

            if ($fatturaPa !== null) {
                return $this->deterministic($job, $fatturaPa['payload'], ['source_format' => 'fatturapa', 'document_type' => $fatturaPa['document_type']]);
            }

            $cii = $this->zugferd->extract($contents);

            if ($cii !== null) {
                return $this->deterministic($job, $cii, ['source_format' => 'factur_x_xml']);
            }

            throw new OcrExtractionException('The XML file is not a readable FatturaPA or Factur-X invoice.');
        }

        if ($mimeType === 'application/pdf') {
            $xml = $this->zugferd->embeddedXml($contents);
            $embedded = $xml !== null ? $this->zugferd->extract($xml) : null;

            if ($embedded !== null) {
                return $this->deterministic($job, $embedded, ['source_format' => 'zugferd']);
            }
        }

        if (! str_starts_with($mimeType, 'image/') && $mimeType !== 'application/pdf') {
            throw new OcrExtractionException("The file type '{$mimeType}' is not supported.");
        }

        $qr = $this->qrBill->extract($contents);

        if ($qr !== null) {
            return $this->hybrid($job, $contents, $mimeType, $qr);
        }

        $ocr = $this->runOcr($job, $contents, $mimeType, []);

        return [
            'payload' => $ocr['payload'],
            'boxes' => $ocr['boxes'],
            'method' => ExtractionMethod::VisionLlmOcr,
            'provider' => $ocr['provider'],
            'tokens' => $ocr['tokens'],
            'meta' => ['source_format' => 'ocr', 'warnings' => []],
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{payload: ExtractedPayloadDto, boxes: array<string, BoundingBoxDto>, method: ExtractionMethod, provider: OcrProviderType|null, tokens: int, meta: array<string, mixed>}
     */
    private function deterministic(DocumentExtractionJob $job, ExtractedPayloadDto $payload, array $meta): array
    {
        $job->update(['document_category' => DocumentCategory::VendorInvoice]);

        return ['payload' => $payload, 'boxes' => [], 'method' => ExtractionMethod::EmbeddedXmlOrQr, 'provider' => null, 'tokens' => 0, 'meta' => [...$meta, 'warnings' => []]];
    }

    /**
     * @param  array{payload: ExtractedPayloadDto, field_keys: list<string>, raw: string}  $qr
     * @return array{payload: ExtractedPayloadDto, boxes: array<string, BoundingBoxDto>, method: ExtractionMethod, provider: OcrProviderType|null, tokens: int, meta: array<string, mixed>}
     */
    private function hybrid(DocumentExtractionJob $job, string $contents, string $mimeType, array $qr): array
    {
        $job->update(['document_category' => DocumentCategory::VendorInvoice]);
        $meta = ['source_format' => 'swiss_qr_bill', 'qr_raw_payload' => $qr['raw'], 'warnings' => []];

        try {
            $ocr = $this->runOcr($job, $contents, $mimeType, $qr['field_keys']);
        } catch (OcrProviderException $exception) {
            // The payment fields are exact; the lines and the VAT split wait for a manual entry.
            $meta['warnings'][] = 'ocr_unavailable';

            return ['payload' => $qr['payload'], 'boxes' => [], 'method' => ExtractionMethod::HybridQrPlusOcr, 'provider' => null, 'tokens' => 0, 'meta' => $meta];
        }

        return [
            'payload' => $ocr['payload']->overlay($qr['payload'], $qr['field_keys']),
            'boxes' => $ocr['boxes'],
            'method' => ExtractionMethod::HybridQrPlusOcr,
            'provider' => $ocr['provider'],
            'tokens' => $ocr['tokens'],
            'meta' => $meta,
        ];
    }

    /**
     * Tries the primary engine of the tenant, then its fallback.
     *
     * @param  list<string>  $skipFieldKeys
     * @return array{payload: ExtractedPayloadDto, boxes: array<string, BoundingBoxDto>, provider: OcrProviderType, tokens: int}
     *
     * @throws OcrProviderException when no engine of the tenant could read the document
     */
    private function runOcr(DocumentExtractionJob $job, string $contents, string $mimeType, array $skipFieldKeys): array
    {
        $errors = [];

        foreach ($this->providers->chainFor((string) $job->tenant_id) as $entry) {
            try {
                $result = $entry['provider']->extract(new OcrExtractionRequest($contents, $mimeType, $job->document_category, $entry['settings'], $skipFieldKeys));

                return ['payload' => $result->payload, 'boxes' => $result->boundingBoxes, 'provider' => $result->provider, 'tokens' => $result->tokensUsed];
            } catch (OcrProviderException $exception) {
                $errors[] = $entry['provider']->type()->value.': '.$exception->getMessage();
            }
        }

        throw new OcrProviderException(implode(' | ', $errors));
    }

    private function fail(DocumentExtractionJob $job, string $reason): void
    {
        $job->update(['status' => ExtractionJobStatus::Failed, 'failure_reason' => mb_substr($reason, 0, 2000)]);
        $this->audit->execute('OCR_EXTRACTION_FAILED', $job, null, ['failure_reason' => $job->failure_reason], 'ocr_extraction');
        $this->announce($job);
    }

    private function announce(DocumentExtractionJob $job): void
    {
        try {
            event(new DocumentExtractionReadyBroadcast((string) $job->tenant_id, (string) $job->id, $job->status->value, $job->document_category->value, (float) $job->overall_confidence_score));
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Touchless posting, off by default: only when the tenant switched it on, the confidence reaches its threshold, the
     * vendor is known and every line is coded. Any rule violation leaves the job for a human.
     */
    private function autoConfirm(DocumentExtractionJob $job): void
    {
        $configuration = TenantOcrConfiguration::query()->where('tenant_id', $job->tenant_id)->first();

        if ($configuration === null || ! $configuration->auto_confirm_enabled
            || (float) $job->overall_confidence_score < (float) $configuration->auto_confirm_confidence_threshold
            || $job->vendor_id === null || $job->document_category !== DocumentCategory::VendorInvoice) {
            return;
        }

        $payload = $job->extracted_payload_json ?? [];
        $lines = [];

        foreach ((array) ($payload['lines'] ?? []) as $line) {
            if (($line['suggested_gl_account_id'] ?? null) === null || ($line['suggested_vat_code_id'] ?? null) === null) {
                return;
            }

            $lines[] = [...$line, 'gl_account_id' => $line['suggested_gl_account_id'], 'vat_code_id' => $line['suggested_vat_code_id'], 'cost_center_id' => $line['suggested_cost_center_id'] ?? null];
        }

        if ($lines === []) {
            return;
        }

        // A purchase order that does not match within tolerance needs a human decision (override reason).
        foreach ((array) ($payload['three_way_match'] ?? []) as $evaluation) {
            if (($evaluation['status'] ?? null) !== 'matched') {
                return;
            }
        }

        try {
            $this->confirm->handle(new ConfirmAndConvertExtractedDocumentCommand((string) $job->id, (string) $job->tenant_id, null, [
                'vendor_id' => $job->vendor_id,
                'header' => $payload['document'] ?? [],
                'totals' => $payload['totals'] ?? [],
                'lines' => $lines,
                'purchase_order_ids' => $job->matched_purchase_order_ids_json ?? [],
                'goods_receipt_ids' => $job->matched_goods_receipt_ids_json ?? [],
            ]));
        } catch (OcrExtractionException) {
            // The job stays ready for review.
        }
    }
}
