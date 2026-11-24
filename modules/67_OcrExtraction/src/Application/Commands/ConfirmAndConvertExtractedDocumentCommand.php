<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Application\Commands;

/**
 * The accountant confirms the reviewed document, which is converted into its business document.
 *
 * @phpstan-type ReviewedPayload array{
 *     category?: string,
 *     vendor_id?: string|null,
 *     header: array<string, mixed>,
 *     totals?: array<string, mixed>,
 *     lines: list<array<string, mixed>>,
 *     purchase_order_ids?: list<string>,
 *     goods_receipt_ids?: list<string>,
 *     match_override_reason?: string|null,
 *     expense_category_id?: string|null,
 *     rfq_id?: string|null,
 *     warehouse_id?: string|null,
 * }
 */
final readonly class ConfirmAndConvertExtractedDocumentCommand
{
    /**
     * @param  array<string, mixed>  $reviewedPayload  accountant-edited header, lines (with GL account, VAT code, cost center), linked PO/GRN ids
     */
    public function __construct(
        public string $jobId,
        public string $tenantId,
        public ?string $userId,
        public array $reviewedPayload,
    ) {}
}
