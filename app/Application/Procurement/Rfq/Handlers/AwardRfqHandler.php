<?php

declare(strict_types=1);

namespace App\Application\Procurement\Rfq\Handlers;

use App\Application\Procurement\Rfq\Commands\AwardRfqCommand;
use App\Application\Procurement\Rfq\DTOs\AwardRfqResult;
use App\Domain\Procurement\Rfq\Contracts\PurchaseOrderAwardCreatorInterface;
use App\Domain\Procurement\Rfq\Contracts\RequisitionSourcingAdapterInterface;
use App\Domain\Procurement\Rfq\Enums\AwardMode;
use App\Domain\Procurement\Rfq\Enums\BidStatus;
use App\Domain\Procurement\Rfq\Enums\InvitationStatus;
use App\Domain\Procurement\Rfq\Enums\RfqStatus;
use App\Domain\Procurement\Rfq\Models\RequestForQuotation;
use App\Domain\Procurement\Rfq\Models\RfqLine;
use App\Domain\Procurement\Rfq\Models\RfqSupplierInvitation;
use App\Domain\Procurement\Rfq\Models\SupplierBid;
use App\Domain\Procurement\Rfq\Models\SupplierBidLine;
use App\Infrastructure\Procurement\Rfq\Mail\RfqAwardNotificationMail;
use App\Infrastructure\Procurement\Rfq\Mail\RfqRegretNotificationMail;
use App\Infrastructure\Procurement\Rfq\Services\RfqSupplierRecipientResolver;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Modules\Procurement\Exceptions\ProcurementRuleViolation;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Services\ProcurementAuditLedgerService;

/**
 * Executes Full Award or Split Award on an RFQ inside an atomic DB transaction:
 * generates one Mod 046 Purchase Order per winning supplier, marks Mod 044 PR lines ordered,
 * transitions RFQ & invitation statuses, and dispatches winner/regret email notifications.
 */
final readonly class AwardRfqHandler
{
    public function __construct(
        private PurchaseOrderAwardCreatorInterface $poCreator,
        private RequisitionSourcingAdapterInterface $requisitions,
        private ProcurementAuditLedgerService $auditLedger,
        private RfqSupplierRecipientResolver $recipientResolver,
    ) {}

    public function handle(AwardRfqCommand $command): AwardRfqResult
    {
        /** @var list<array{email: string, locale: string, supplier_name: string, is_partial: bool, po_numbers: list<string>}> $awardEmails */
        $awardEmails = [];
        /** @var list<array{email: string, locale: string, supplier_name: string}> $regretEmails */
        $regretEmails = [];

        $result = DB::transaction(function () use ($command, &$awardEmails, &$regretEmails): AwardRfqResult {
            /** @var RequestForQuotation $rfq */
            $rfq = RequestForQuotation::query()
                ->with(['lines', 'invitations.supplier', 'bids.lines'])
                ->whereKey($command->rfqId)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $rfq->statusEnum()->canBeAwarded()) {
                throw new ProcurementRuleViolation("An RFQ in status {$rfq->status} cannot be awarded.", 'status');
            }

            if ($rfq->isSealedNow()) {
                throw new ProcurementRuleViolation('Bids are sealed until the submission deadline has passed.', 'sealed_bidding');
            }

            $evaluatableBids = $rfq->bids->filter(
                static fn (SupplierBid $bid): bool => $bid->statusEnum()->isEvaluatable()
            );

            $rfqLinesById = $rfq->lines->keyBy('id');
            $bidsById = $evaluatableBids->keyBy('id');
            $bidsBySupplierId = $evaluatableBids->keyBy('supplier_id');

            /** @var list<array{rfq_line: RfqLine, bid: SupplierBid, bid_line: SupplierBidLine}> $winningItems */
            $winningItems = [];
            $today = CarbonImmutable::now('UTC')->toDateString();

            if ($command->awardMode === AwardMode::Full) {
                $winningBid = match (true) {
                    $command->bidId !== null => $bidsById->get($command->bidId),
                    $command->supplierId !== null => $bidsBySupplierId->get($command->supplierId),
                    default => null,
                };

                if (! $winningBid instanceof SupplierBid) {
                    throw new ProcurementRuleViolation('The selected supplier bid does not belong to this RFQ or was not submitted.', 'bid_id');
                }

                $this->assertBidNotExpired($winningBid, $today);

                foreach ($winningBid->lines as $bidLine) {
                    if ($bidLine->is_no_bid) {
                        continue;
                    }
                    /** @var RfqLine|null $rfqLine */
                    $rfqLine = $rfqLinesById->get((string) $bidLine->rfq_line_id);
                    if ($rfqLine !== null) {
                        $winningItems[] = [
                            'rfq_line' => $rfqLine,
                            'bid' => $winningBid,
                            'bid_line' => $bidLine,
                        ];
                    }
                }

                if ($winningItems === []) {
                    throw new ProcurementRuleViolation('The winning bid contains no quoted line items.', 'bid_id');
                }
            } else {
                if ($command->lineAwards === []) {
                    throw new ProcurementRuleViolation('Split award requires at least one line allocation.', 'line_awards');
                }

                $bidLinesById = [];
                foreach ($evaluatableBids as $bid) {
                    foreach ($bid->lines as $bidLine) {
                        $bidLinesById[(string) $bidLine->id] = ['bid' => $bid, 'bid_line' => $bidLine];
                    }
                }

                $seenRfqLines = [];
                foreach ($command->lineAwards as $index => $allocation) {
                    $rfqLineId = $allocation['rfq_line_id'];
                    $bidLineId = $allocation['supplier_bid_line_id'];

                    if (isset($seenRfqLines[$rfqLineId])) {
                        throw new ProcurementRuleViolation("Duplicate RFQ line {$rfqLineId} in split award allocation.", "line_awards.{$index}.rfq_line_id");
                    }
                    $seenRfqLines[$rfqLineId] = true;

                    /** @var RfqLine|null $rfqLine */
                    $rfqLine = $rfqLinesById->get($rfqLineId);
                    if ($rfqLine === null) {
                        throw new ProcurementRuleViolation("RFQ line {$rfqLineId} does not belong to this RFQ.", "line_awards.{$index}.rfq_line_id");
                    }

                    $resolved = $bidLinesById[$bidLineId] ?? null;
                    if ($resolved === null) {
                        throw new ProcurementRuleViolation("Bid line {$bidLineId} does not belong to a submitted bid of this RFQ.", "line_awards.{$index}.supplier_bid_line_id");
                    }

                    /** @var SupplierBid $bid */
                    $bid = $resolved['bid'];
                    /** @var SupplierBidLine $bidLine */
                    $bidLine = $resolved['bid_line'];

                    if ((string) $bidLine->rfq_line_id !== $rfqLineId) {
                        throw new ProcurementRuleViolation('Bid line does not match the specified RFQ line.', "line_awards.{$index}.supplier_bid_line_id");
                    }
                    if ($bidLine->is_no_bid) {
                        throw new ProcurementRuleViolation('Cannot award a line marked as No Bid.', "line_awards.{$index}.supplier_bid_line_id");
                    }

                    $this->assertBidNotExpired($bid, $today);

                    $winningItems[] = [
                        'rfq_line' => $rfqLine,
                        'bid' => $bid,
                        'bid_line' => $bidLine,
                    ];
                }
            }

            // Reset any prior award markers on lines and mark winning bid lines
            $winningBidLineIds = array_map(static fn (array $item): string => (string) $item['bid_line']->id, $winningItems);
            $allBidIds = $evaluatableBids->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all();

            if ($allBidIds !== []) {
                SupplierBidLine::query()->whereIn('supplier_quote_id', $allBidIds)->update(['is_awarded' => false]);
            }
            SupplierBidLine::query()->whereIn('id', $winningBidLineIds)->update(['is_awarded' => true]);

            // Group winning items by supplier_id to create one Purchase Order per winning supplier
            /** @var array<string, list<array{rfq_line: RfqLine, bid: SupplierBid, bid_line: SupplierBidLine}>> $groupedBySupplier */
            $groupedBySupplier = [];
            foreach ($winningItems as $item) {
                $supplierId = (string) $item['bid']->supplier_id;
                $groupedBySupplier[$supplierId][] = $item;
            }

            /** @var list<PurchaseOrder> $purchaseOrders */
            $purchaseOrders = [];
            /** @var array<string, list<string>> $poNumbersBySupplier */
            $poNumbersBySupplier = [];
            $winningBidIds = [];
            $winningLineCountsBySupplier = [];

            foreach ($groupedBySupplier as $supplierId => $supplierItems) {
                $order = $this->poCreator->execute(
                    rfq: $rfq,
                    supplierId: $supplierId,
                    awardedItems: $supplierItems,
                    actorUserId: $command->actorUserId,
                    expectedDeliveryDate: $command->expectedDeliveryDate,
                    notes: $command->notes,
                );

                $purchaseOrders[] = $order;
                $poNumbersBySupplier[$supplierId][] = (string) ($order->po_number ?? $order->order_number);
                $winningLineCountsBySupplier[$supplierId] = count($supplierItems);

                foreach ($supplierItems as $item) {
                    $winningBidIds[(string) $item['bid']->id] = true;
                    $item['rfq_line']->update([
                        'awarded_supplier_id' => $supplierId,
                        'awarded_bid_line_id' => $item['bid_line']->id,
                        'generated_purchase_order_id' => $order->id,
                    ]);
                }
            }

            // Update SupplierBid statuses
            $winningBidIdList = array_keys($winningBidIds);
            SupplierBid::query()->whereIn('id', $winningBidIdList)->update(['status' => BidStatus::Awarded->value]);
            SupplierBid::query()
                ->where('rfq_id', $rfq->id)
                ->whereIn('status', [BidStatus::Submitted->value, BidStatus::Revised->value, BidStatus::Received->value])
                ->whereNotIn('id', $winningBidIdList)
                ->update(['status' => BidStatus::Rejected->value]);

            // Mark linked Mod 044 PR lines ordered
            $this->requisitions->markRfqLinesOrdered($rfq);

            $now = CarbonImmutable::now('UTC');
            $singleWinnerSupplierId = count($groupedBySupplier) === 1 ? (string) array_key_first($groupedBySupplier) : null;
            $singleWinningBidId = count($winningBidIdList) === 1 ? $winningBidIdList[0] : null;
            $singlePoId = count($purchaseOrders) === 1 ? (string) $purchaseOrders[0]->id : null;

            $rfq->update([
                'status' => RfqStatus::Awarded->value,
                'award_mode' => $command->awardMode->value,
                'awarded_at' => $now,
                'awarded_by_user_id' => $command->actorUserId,
                'awarded_supplier_id' => $singleWinnerSupplierId,
                'awarded_quote_id' => $singleWinningBidId,
                'purchase_order_id' => $singlePoId,
            ]);

            $tenant = Tenant::query()->find($rfq->tenant_id);
            $totalRfqLines = $rfq->lines->count();

            foreach ($rfq->invitations as $invitation) {
                /** @var RfqSupplierInvitation $invitation */
                $supplierId = (string) $invitation->supplier_id;
                $isWinner = isset($groupedBySupplier[$supplierId]);

                if ($isWinner) {
                    $isPartial = $command->awardMode === AwardMode::Split
                        && (($winningLineCountsBySupplier[$supplierId] ?? 0) < $totalRfqLines || count($groupedBySupplier) > 1);

                    $invitation->update([
                        'status' => $isPartial ? InvitationStatus::AwardedPartial->value : InvitationStatus::AwardedFull->value,
                        'notified_outcome_at' => $now,
                    ]);

                    if ($invitation->supplier !== null) {
                        $recipient = $this->recipientResolver->resolve($invitation->supplier, $tenant);
                        if ($recipient['email'] !== null) {
                            $awardEmails[] = [
                                'email' => $recipient['email'],
                                'locale' => $recipient['locale'],
                                'supplier_name' => $recipient['supplier_name'],
                                'is_partial' => $isPartial,
                                'po_numbers' => $poNumbersBySupplier[$supplierId] ?? [],
                            ];
                        }
                    }
                } else {
                    $wasDeclined = $invitation->statusEnum() === InvitationStatus::Declined;

                    if (! $wasDeclined) {
                        $invitation->update([
                            'status' => InvitationStatus::NotAwarded->value,
                            'notified_outcome_at' => $now,
                        ]);

                        if ($invitation->supplier !== null) {
                            $recipient = $this->recipientResolver->resolve($invitation->supplier, $tenant);
                            if ($recipient['email'] !== null) {
                                $regretEmails[] = [
                                    'email' => $recipient['email'],
                                    'locale' => $recipient['locale'],
                                    'supplier_name' => $recipient['supplier_name'],
                                ];
                            }
                        }
                    }
                }
            }

            $this->auditLedger->record(
                action: 'RFQ_AWARDED',
                model: $rfq,
                payloadBefore: ['status' => RfqStatus::BiddingOpen->value],
                payloadAfter: [
                    'award_mode' => $command->awardMode->value,
                    'winning_suppliers' => array_keys($groupedBySupplier),
                    'purchase_order_ids' => array_map(static fn (PurchaseOrder $po): string => (string) $po->id, $purchaseOrders),
                ]
            );

            return new AwardRfqResult(
                rfq: $rfq->fresh(['lines', 'attachments', 'invitations.supplier', 'bids.lines', 'bids.supplier', 'buyer', 'awardedBy']) ?? $rfq,
                purchaseOrders: $purchaseOrders,
            );
        });

        $tenant = Tenant::query()->find($result->rfq->tenant_id);
        $buyerCompanyName = (string) ($tenant?->legal_name ?: $tenant?->name ?: config('app.name', 'FatturaFacile'));
        $rfq = $result->rfq;

        DB::afterCommit(static function () use ($awardEmails, $regretEmails, $rfq, $buyerCompanyName): void {
            foreach ($awardEmails as $email) {
                Mail::to($email['email'])
                    ->locale($email['locale'])
                    ->queue(new RfqAwardNotificationMail(
                        rfqId: (string) $rfq->id,
                        rfqNumber: (string) $rfq->rfq_number,
                        rfqTitle: (string) $rfq->title,
                        buyerCompanyName: $buyerCompanyName,
                        supplierName: $email['supplier_name'],
                        isPartialAward: $email['is_partial'],
                        awardedPoNumbers: $email['po_numbers'],
                        mailLocale: $email['locale'],
                    ));
            }

            foreach ($regretEmails as $email) {
                Mail::to($email['email'])
                    ->locale($email['locale'])
                    ->queue(new RfqRegretNotificationMail(
                        rfqId: (string) $rfq->id,
                        rfqNumber: (string) $rfq->rfq_number,
                        rfqTitle: (string) $rfq->title,
                        buyerCompanyName: $buyerCompanyName,
                        supplierName: $email['supplier_name'],
                        mailLocale: $email['locale'],
                    ));
            }
        });

        return $result;
    }

    private function assertBidNotExpired(SupplierBid $bid, string $today): void
    {
        if ($bid->valid_until !== null && $bid->valid_until->toDateString() < $today) {
            throw new ProcurementRuleViolation('The selected supplier bid has expired and cannot be awarded.', 'bid_id');
        }
    }
}
