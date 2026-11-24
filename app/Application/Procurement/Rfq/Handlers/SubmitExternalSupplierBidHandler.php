<?php

declare(strict_types=1);

namespace App\Application\Procurement\Rfq\Handlers;

use App\Application\Procurement\Rfq\Commands\SubmitExternalSupplierBidCommand;
use App\Application\Procurement\Rfq\Services\RfqScoreSynchronizer;
use App\Domain\Procurement\Rfq\Contracts\ExchangeRateResolverInterface;
use App\Domain\Procurement\Rfq\Enums\BidStatus;
use App\Domain\Procurement\Rfq\Enums\InvitationStatus;
use App\Domain\Procurement\Rfq\Models\RequestForQuotation;
use App\Domain\Procurement\Rfq\Models\RfqLine;
use App\Domain\Procurement\Rfq\Models\RfqSupplierInvitation;
use App\Domain\Procurement\Rfq\Models\SupplierBid;
use App\Domain\Procurement\Rfq\Models\SupplierBidLine;
use App\Infrastructure\Procurement\Rfq\Services\RfqAttachmentStorageService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Procurement\Exceptions\ProcurementRuleViolation;
use Modules\Procurement\Services\ProcurementAuditLedgerService;
use Modules\Procurement\Support\Decimal;

/**
 * Persists or revises an external supplier's bid (draft or final submission) and recalculates scoring.
 */
final readonly class SubmitExternalSupplierBidHandler
{
    public function __construct(
        private ExchangeRateResolverInterface $exchangeRates,
        private RfqAttachmentStorageService $attachmentStorage,
        private RfqScoreSynchronizer $scoreSynchronizer,
        private ProcurementAuditLedgerService $auditLedger,
    ) {}

    public function handle(SubmitExternalSupplierBidCommand $command): SupplierBid
    {
        /** @var RfqSupplierInvitation $invitation */
        $invitation = RfqSupplierInvitation::query()
            ->with('rfq.lines')
            ->whereKey($command->invitationId)
            ->firstOrFail();

        /** @var RequestForQuotation $rfq */
        $rfq = $invitation->rfq ?? throw new ProcurementRuleViolation('RFQ record not found for invitation.', 'rfq_id');

        $now = CarbonImmutable::now('UTC');

        if (! $rfq->statusEnum()->isOpenForBidding()) {
            throw new ProcurementRuleViolation('This RFQ is no longer open for bidding.', 'status');
        }

        if ($now->gt($rfq->effectiveDeadline())) {
            throw new ProcurementRuleViolation('The submission deadline for this RFQ has passed.', 'submission_deadline');
        }

        if (! $invitation->statusEnum()->canSubmitBid()) {
            throw new ProcurementRuleViolation('This supplier invitation is no longer eligible to submit bids.', 'invitation');
        }

        $isFinalSubmit = $command->action !== 'draft';
        $existingBid = SupplierBid::query()
            ->where('rfq_id', $rfq->id)
            ->where('supplier_id', $invitation->supplier_id)
            ->first();

        if (! $isFinalSubmit && $existingBid !== null && $existingBid->statusEnum()->isEvaluatable()) {
            throw new ProcurementRuleViolation('A submitted bid cannot be reverted to draft; submit a revision instead.', 'action');
        }

        $rfqLinesById = $rfq->lines->keyBy('id');
        $seenLineIds = [];
        $nonNoBidCount = 0;

        foreach ($command->lines as $index => $lineInput) {
            $rfqLineId = $lineInput['rfq_line_id'];
            if (! $rfqLinesById->has($rfqLineId)) {
                throw new ProcurementRuleViolation("Bid line {$index} references a line not belonging to this RFQ.", "lines.{$index}.rfq_line_id");
            }
            if (isset($seenLineIds[$rfqLineId])) {
                throw new ProcurementRuleViolation("Duplicate RFQ line {$rfqLineId} in bid payload.", "lines.{$index}.rfq_line_id");
            }
            $seenLineIds[$rfqLineId] = true;

            $isNoBid = (bool) ($lineInput['is_no_bid'] ?? false);
            if (! $isNoBid) {
                $nonNoBidCount++;
                if ($isFinalSubmit && (! isset($lineInput['unit_price']) || Decimal::cmp((string) $lineInput['unit_price'], '0', 4) < 0)) {
                    throw new ProcurementRuleViolation('A valid non-negative unit price is required for quoted lines.', "lines.{$index}.unit_price");
                }
            }
        }

        if ($isFinalSubmit && $nonNoBidCount === 0) {
            throw new ProcurementRuleViolation('At least one line must be quoted to submit a bid. Use decline if you cannot bid.', 'lines');
        }

        $bidId = $existingBid !== null ? (string) $existingBid->id : (string) Str::uuid7();
        $targetBid = $existingBid ?? new SupplierBid([
            'id' => $bidId,
            'tenant_id' => $rfq->tenant_id,
            'rfq_id' => $rfq->id,
            'supplier_id' => $invitation->supplier_id,
        ]);

        // Store optional quote attachment outside DB transaction
        $attachmentMeta = null;
        if ($command->quoteAttachment !== null) {
            $attachmentMeta = $this->attachmentStorage->storeBidAttachment($targetBid, $command->quoteAttachment);
        }

        $bid = DB::transaction(function () use ($command, $invitation, $rfq, $rfqLinesById, $existingBid, $bidId, $isFinalSubmit, $attachmentMeta, $now): SupplierBid {
            /** @var RequestForQuotation $lockedRfq */
            $lockedRfq = RequestForQuotation::query()->whereKey($rfq->id)->lockForUpdate()->firstOrFail();
            if (! $lockedRfq->statusEnum()->isOpenForBidding() || CarbonImmutable::now('UTC')->gt($lockedRfq->effectiveDeadline())) {
                throw new ProcurementRuleViolation('The RFQ bidding window has closed.', 'submission_deadline');
            }

            $currency = strtoupper(trim($command->currencyCode !== '' ? $command->currencyCode : $lockedRfq->effectiveCurrencyCode()));
            $exchangeRate = $command->exchangeRateToRfq !== null && Decimal::cmp($command->exchangeRateToRfq, '0', 6) > 0
                ? Decimal::of($command->exchangeRateToRfq, 6)
                : $this->exchangeRates->resolveRate((string) $lockedRfq->tenant_id, $currency, $lockedRfq->effectiveCurrencyCode());

            $shippingCost = Decimal::of($command->shippingCost, 4);

            $status = match (true) {
                ! $isFinalSubmit => BidStatus::Draft->value,
                $existingBid !== null && $existingBid->statusEnum()->isEvaluatable() => BidStatus::Revised->value,
                default => BidStatus::Submitted->value,
            };

            $payload = [
                'tenant_id' => $lockedRfq->tenant_id,
                'rfq_id' => $lockedRfq->id,
                'invitation_id' => $invitation->id,
                'supplier_id' => $invitation->supplier_id,
                'quote_reference' => $command->supplierQuoteReference,
                'quote_date' => $now->toDateString(),
                'valid_until' => $command->validUntil,
                'currency' => $currency,
                'exchange_rate_to_rfq' => $exchangeRate,
                'payment_terms_days' => max(0, $command->paymentTermsDays),
                'incoterms' => $command->incoterms,
                'shipping_cost' => $shippingCost,
                'lead_time_days' => $this->deriveMaxLeadTime($command->lines),
                'status' => $status,
                'notes' => $command->generalNotes,
                'submitted_ip_address' => $command->ipAddress,
                'submitted_user_agent' => mb_substr($command->userAgent, 0, 500),
                'submitted_at' => $isFinalSubmit ? $now : null,
            ];

            if ($attachmentMeta !== null) {
                $payload['quote_attachment_file_name'] = $attachmentMeta['file_name'];
                $payload['quote_attachment_mime_type'] = $attachmentMeta['mime_type'];
                $payload['quote_attachment_size_bytes'] = $attachmentMeta['size_bytes'];
                $payload['quote_attachment_disk'] = $attachmentMeta['disk'];
                $payload['quote_attachment_path'] = $attachmentMeta['path'];
                $payload['quote_attachment_sha256'] = $attachmentMeta['sha256'];
            }

            if ($existingBid !== null) {
                $existingBid->update($payload);
                $bid = $existingBid;
                SupplierBidLine::query()->where('supplier_quote_id', $bid->id)->forceDelete();
            } else {
                $bid = SupplierBid::create(['id' => $bidId, ...$payload]);
            }

            $totalNet = '0.0000';

            foreach ($command->lines as $lineInput) {
                /** @var RfqLine $rfqLine */
                $rfqLine = $rfqLinesById->get($lineInput['rfq_line_id']);
                $isNoBid = (bool) ($lineInput['is_no_bid'] ?? false);
                $qty = Decimal::of($lineInput['quoted_quantity'] ?? $rfqLine->quantity, 4);
                $unitPrice = $isNoBid ? '0.0000' : Decimal::of($lineInput['unit_price'] ?? '0', 4);
                $discount = $isNoBid ? '0.00' : Decimal::of($lineInput['discount_percent'] ?? '0', 2);
                $discountFactor = bcsub('1', bcdiv($discount, '100', 8), 8);
                $netUnitPrice = $isNoBid ? '0.0000' : Decimal::mul($unitPrice, $discountFactor, 4);
                $lineTotalNet = $isNoBid ? '0.0000' : Decimal::mul($netUnitPrice, $qty, 4);
                $totalNet = Decimal::add($totalNet, $lineTotalNet, 4);

                SupplierBidLine::create([
                    'tenant_id' => $lockedRfq->tenant_id,
                    'supplier_quote_id' => $bid->id,
                    'rfq_line_id' => $rfqLine->id,
                    'is_no_bid' => $isNoBid,
                    'quantity' => $qty,
                    'unit_price' => $unitPrice,
                    'discount_percent' => $discount,
                    'net_unit_price' => $netUnitPrice,
                    'tax_rate' => Decimal::of($lineInput['tax_rate'] ?? '22.00', 2),
                    'line_total' => Decimal::round($lineTotalNet, 2),
                    'line_total_net' => $lineTotalNet,
                    'lead_time_days' => max(0, (int) ($lineInput['lead_time_days'] ?? 0)),
                    'promised_delivery_date' => $lineInput['promised_delivery_date'] ?? null,
                    'supplier_notes' => $lineInput['supplier_notes'] ?? null,
                    'is_awarded' => false,
                ]);
            }

            $totalLanded = Decimal::add($totalNet, $shippingCost, 4);
            $bid->update([
                'total_net_amount' => $totalNet,
                'total_landed_cost' => $totalLanded,
                'total_amount' => Decimal::round($totalNet, 2),
            ]);

            if ($isFinalSubmit) {
                $invitation->update([
                    'status' => InvitationStatus::BidSubmitted->value,
                    'submitted_at' => $now,
                    'responded_at' => $now,
                ]);

                $this->scoreSynchronizer->synchronize($lockedRfq);
            }

            $this->auditLedger->record(
                action: $isFinalSubmit ? 'RFQ_BID_SUBMITTED' : 'RFQ_BID_DRAFT_SAVED',
                model: $bid,
                payloadBefore: null,
                payloadAfter: [
                    'rfq_id' => $lockedRfq->id,
                    'supplier_id' => $invitation->supplier_id,
                    'status' => $bid->status,
                    'total_landed_cost' => $totalLanded,
                ]
            );

            return $bid->fresh(['lines', 'supplier']) ?? $bid;
        });

        return $bid;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function deriveMaxLeadTime(array $lines): ?int
    {
        $max = null;
        foreach ($lines as $line) {
            if (! ($line['is_no_bid'] ?? false) && isset($line['lead_time_days'])) {
                $days = max(0, (int) $line['lead_time_days']);
                if ($max === null || $days > $max) {
                    $max = $days;
                }
            }
        }

        return $max;
    }
}
