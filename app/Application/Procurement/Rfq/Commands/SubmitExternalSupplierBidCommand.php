<?php

declare(strict_types=1);

namespace App\Application\Procurement\Rfq\Commands;

use Illuminate\Http\UploadedFile;

/**
 * Command for an external supplier (authenticated by invitation token) to save a draft or submit/revise a bid.
 */
final readonly class SubmitExternalSupplierBidCommand
{
    /**
     * @param  list<array{rfq_line_id: string, is_no_bid?: bool, quoted_quantity?: string|int|float|null, unit_price?: string|int|float|null, discount_percent?: string|int|float|null, lead_time_days?: int|null, promised_delivery_date?: ?string, supplier_notes?: ?string, tax_rate?: string|int|float|null}>  $lines
     */
    public function __construct(
        public string $invitationId,
        public string $action,
        public ?string $supplierQuoteReference,
        public string $currencyCode,
        public ?string $exchangeRateToRfq,
        public int $paymentTermsDays,
        public ?string $incoterms,
        public string|int|float $shippingCost,
        public ?string $validUntil,
        public ?string $generalNotes,
        public array $lines,
        public ?UploadedFile $quoteAttachment,
        public string $ipAddress,
        public string $userAgent,
    ) {}
}
