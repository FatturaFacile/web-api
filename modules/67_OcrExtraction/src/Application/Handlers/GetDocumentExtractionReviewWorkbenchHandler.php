<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Application\Handlers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Modules\Documents\Models\DmsDocumentVersion;
use Modules\OcrExtraction\Application\Queries\GetDocumentExtractionReviewWorkbenchQuery;
use Modules\OcrExtraction\Domain\Enums\ExtractionJobStatus;
use Modules\OcrExtraction\Domain\Exceptions\OcrExtractionNotFoundException;
use Modules\OcrExtraction\Infrastructure\Models\DocumentExtractionJob;
use Modules\OcrExtraction\Infrastructure\Services\OcrTenantRunner;

/**
 * Read model of the AI Inbox: the paginated queue (Queued, Processing, ReadyForReview, Failed) with counters and, for a
 * selected job, everything the review workbench shows: a temporary signed URL of the source file, the payload with its
 * bounding boxes and confidences, the vendor match, the coding lookups and the three-way match candidates.
 */
final readonly class GetDocumentExtractionReviewWorkbenchHandler
{
    private const int LookupLimit = 1000;

    public function __construct(private OcrTenantRunner $runner) {}

    /**
     * @return array{counters: array<string, int>, queue: list<array<string, mixed>>, meta: array<string, int>, selected: array<string, mixed>|null}
     */
    public function handle(GetDocumentExtractionReviewWorkbenchQuery $query): array
    {
        return $this->runner->run($query->tenantId, function () use ($query): array {
            $inbox = array_map(static fn (ExtractionJobStatus $status): string => $status->value, ExtractionJobStatus::inbox());

            $counters = array_fill_keys($inbox, 0);

            foreach (DocumentExtractionJob::query()->where('tenant_id', $query->tenantId)->whereIn('status', $inbox)->selectRaw('status, COUNT(*) as total')->groupBy('status')->get() as $row) {
                $counters[$row->status->value] = (int) $row->getAttribute('total');
            }

            $listing = DocumentExtractionJob::query()
                ->where('tenant_id', $query->tenantId)
                ->whereIn('status', $query->status !== null ? [$query->status->value] : $inbox)
                ->orderByDesc('created_at')
                ->paginate(max(1, min(100, $query->perPage)), ['*'], 'page', max(1, $query->page));

            $titles = DB::table('dms_documents')->whereIn('id', $listing->pluck('dms_document_id')->all())->pluck('title', 'id');
            $vendors = DB::table('contacts')->whereIn('id', $listing->pluck('vendor_id')->filter()->all())->pluck('company_name', 'id');

            $queue = $listing->getCollection()->map(static fn (DocumentExtractionJob $job): array => [
                'id' => (string) $job->id,
                'status' => $job->status->value,
                'document_category' => $job->document_category->value,
                'source_channel' => $job->source_channel->value,
                'extraction_method' => $job->extraction_method?->value,
                'ocr_provider_used' => $job->ocr_provider_used?->value,
                'overall_confidence_score' => (float) $job->overall_confidence_score,
                'dms_document_id' => (string) $job->dms_document_id,
                'document_title' => $titles[$job->dms_document_id] ?? null,
                'vendor_id' => $job->vendor_id,
                'vendor_name' => $job->vendor_id !== null ? ($vendors[$job->vendor_id] ?? null) : null,
                'failure_reason' => $job->failure_reason,
                'created_at' => $job->created_at?->toIso8601String(),
            ])->values()->all();

            return [
                'counters' => $counters,
                'queue' => $queue,
                'meta' => ['current_page' => $listing->currentPage(), 'last_page' => $listing->lastPage(), 'per_page' => $listing->perPage(), 'total' => $listing->total()],
                'selected' => $query->jobId !== null ? $this->workbench($query->tenantId, $query->jobId) : null,
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function workbench(string $tenantId, string $jobId): array
    {
        $job = DocumentExtractionJob::query()->where('tenant_id', $tenantId)->find($jobId);

        if ($job === null) {
            throw new OcrExtractionNotFoundException('The extraction job does not exist.');
        }

        $version = DmsDocumentVersion::query()->where('document_id', $job->dms_document_id)->orderByDesc('version_number')->first();
        $payload = $job->extracted_payload_json ?? [];

        return [
            'id' => (string) $job->id,
            'status' => $job->status->value,
            'document_category' => $job->document_category->value,
            'source_channel' => $job->source_channel->value,
            'extraction_method' => $job->extraction_method?->value,
            'ocr_provider_used' => $job->ocr_provider_used?->value,
            'overall_confidence_score' => (float) $job->overall_confidence_score,
            'llm_tokens_used' => $job->llm_tokens_used,
            'failure_reason' => $job->failure_reason,
            'source' => [
                'url' => URL::temporarySignedRoute('ocr-inbox.source', now()->addMinutes((int) config('ocr_extraction.signed_url_minutes', 15)), ['job' => $job->id]),
                'mime_type' => $version?->mime_type,
                'file_name' => $version?->original_name,
            ],
            'payload' => $payload,
            'bounding_boxes' => $job->bounding_boxes_json ?? (object) [],
            'review_draft' => $job->review_draft_json,
            'vendor' => $this->vendor($tenantId, $job->vendor_id),
            'lookups' => $this->lookups($tenantId),
            'three_way_match' => $this->matches($tenantId, $job->matched_purchase_order_ids_json ?? [], (array) ($payload['three_way_match'] ?? [])),
            'resulting_entity' => $job->resulting_entity_id !== null ? ['type' => $job->resulting_entity_type, 'id' => $job->resulting_entity_id] : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function vendor(string $tenantId, ?string $vendorId): ?array
    {
        if ($vendorId === null) {
            return null;
        }

        $vendor = DB::table('contacts')->where('tenant_id', $tenantId)->where('id', $vendorId)->first(['id', 'company_name', 'vat_number', 'uid_number', 'iban', 'qr_iban', 'country']);

        return $vendor !== null ? (array) $vendor : null;
    }

    /**
     * Options of the coding selects: GL accounts, VAT codes of the tenant's jurisdictions, cost centers.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function lookups(string $tenantId): array
    {
        $visible = static fn ($query) => $query->where('tenant_id', $tenantId)->orWhereNull('tenant_id');

        return [
            'gl_accounts' => DB::table('chart_of_accounts')->where($visible)->whereNull('deleted_at')->where('is_active', true)->orderBy('code')->limit(self::LookupLimit)
                ->get(['id', 'code', 'name'])->map(static fn (object $row): array => (array) $row)->all(),
            'vat_codes' => DB::table('vat_codes')->where($visible)->whereNull('deleted_at')->where('is_active', true)->orderBy('code')->limit(self::LookupLimit)
                ->get(['id', 'code', 'description', 'rate', 'tax_rate_percent', 'country_iso', 'is_reverse_charge'])->map(static fn (object $row): array => (array) $row)->all(),
            'cost_centers' => DB::table('cost_centers')->where($visible)->whereNull('deleted_at')->where('is_active', true)->orderBy('code')->limit(self::LookupLimit)
                ->get(['id', 'code', 'name'])->map(static fn (object $row): array => (array) $row)->all(),
        ];
    }

    /**
     * Candidate purchase orders with their lines and goods receipts, and the variances measured on extraction.
     *
     * @param  list<string>  $orderIds
     * @param  array<int, mixed>  $evaluations
     * @return array{purchase_orders: list<array<string, mixed>>}
     */
    private function matches(string $tenantId, array $orderIds, array $evaluations): array
    {
        $byOrder = [];

        foreach ($evaluations as $evaluation) {
            $byOrder[(string) ($evaluation['purchase_order_id'] ?? '')] = $evaluation;
        }

        $orders = [];

        foreach ($orderIds as $orderId) {
            $order = DB::table('purchase_orders')->where('tenant_id', $tenantId)->where('id', $orderId)->first(['id', 'po_number', 'order_number', 'status', 'currency', 'total_gross']);

            if ($order === null) {
                continue;
            }

            $orders[] = [
                'id' => (string) $order->id,
                'number' => (string) ($order->po_number ?? $order->order_number),
                'status' => $order->status,
                'currency' => $order->currency,
                'total' => (float) $order->total_gross,
                'lines' => DB::table('purchase_order_lines')->where('tenant_id', $tenantId)->where('purchase_order_id', $orderId)->whereNull('deleted_at')->orderBy('line_number')
                    ->get(['id', 'description', 'quantity_ordered', 'quantity_received', 'unit_price'])->map(static fn (object $row): array => (array) $row)->all(),
                'goods_receipts' => DB::table('purchase_receipts')->where('tenant_id', $tenantId)->where('purchase_order_id', $orderId)->whereNull('deleted_at')->orderBy('received_date')
                    ->get(['id', 'receipt_number', 'received_date'])->map(static fn (object $row): array => (array) $row)->all(),
                'evaluation' => $byOrder[(string) $order->id] ?? null,
            ];
        }

        return ['purchase_orders' => $orders];
    }
}
