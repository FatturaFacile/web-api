<?php

declare(strict_types=1);

namespace App\Application\Procurement\Rfq\Handlers;

use App\Application\Procurement\Rfq\Queries\GetBidComparisonMatrixQuery;
use App\Domain\Procurement\Rfq\Contracts\SupplierRatingResolverInterface;
use App\Domain\Procurement\Rfq\Models\RequestForQuotation;
use App\Domain\Procurement\Rfq\Models\RfqLine;
use App\Domain\Procurement\Rfq\Models\RfqSupplierInvitation;
use App\Domain\Procurement\Rfq\Models\SupplierBid;
use App\Domain\Procurement\Rfq\Models\SupplierBidLine;
use App\Domain\Procurement\Rfq\Services\BidScoringDomainService;
use App\Domain\Procurement\Rfq\ValueObjects\BidHeaderInput;
use App\Domain\Procurement\Rfq\ValueObjects\BidLineInput;
use App\Domain\Procurement\Rfq\ValueObjects\BidScoreResult;
use App\Domain\Procurement\Rfq\ValueObjects\LineScoreResult;
use Modules\Procurement\Support\Decimal;

/**
 * Produces the structured side-by-side Bid Comparison Matrix DTO, strictly enforcing
 * sealed bidding redaction while submission_deadline has not yet passed.
 */
final readonly class GetBidComparisonMatrixHandler
{
    public function __construct(
        private BidScoringDomainService $scoring,
        private SupplierRatingResolverInterface $ratingResolver,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(GetBidComparisonMatrixQuery $query): array
    {
        /** @var RequestForQuotation $rfq */
        $rfq = RequestForQuotation::query()
            ->with(['lines', 'invitations.supplier', 'bids.lines', 'bids.supplier'])
            ->findOrFail($query->rfqId);

        $isSealed = $rfq->isSealedNow();
        $evaluatableBids = $rfq->bids->filter(
            static fn (SupplierBid $bid): bool => $bid->statusEnum()->isEvaluatable()
        )->values();

        $supplierIds = $rfq->invitations
            ->pluck('supplier_id')
            ->merge($evaluatableBids->pluck('supplier_id'))
            ->map(static fn (mixed $id): string => (string) $id)
            ->unique()
            ->values()
            ->all();

        $ratings = $this->ratingResolver->resolveRatings($supplierIds);

        if ($isSealed) {
            return $this->sealedMatrix($rfq, $evaluatableBids->all(), $ratings);
        }

        $rfqLineIds = $rfq->lines->pluck('id')->map(static fn (mixed $id): string => (string) $id)->values()->all();
        $inputs = [];

        foreach ($evaluatableBids as $bid) {
            /** @var SupplierBid $bid */
            $lineInputs = [];
            foreach ($bid->lines as $line) {
                /** @var SupplierBidLine $line */
                $lineInputs[] = new BidLineInput(
                    bidLineId: (string) $line->id,
                    rfqLineId: (string) $line->rfq_line_id,
                    isNoBid: (bool) $line->is_no_bid,
                    quotedQuantity: (string) $line->quantity,
                    unitPrice: (string) $line->unit_price,
                    discountPercent: (string) ($line->discount_percent ?? '0.00'),
                    leadTimeDays: max(0, (int) ($line->lead_time_days ?? $bid->lead_time_days ?? 0)),
                );
            }

            $inputs[] = new BidHeaderInput(
                bidId: (string) $bid->id,
                supplierId: (string) $bid->supplier_id,
                currencyCode: (string) $bid->currency,
                exchangeRateToRfq: (string) ($bid->exchange_rate_to_rfq > 0 ? $bid->exchange_rate_to_rfq : '1.000000'),
                shippingCost: (string) ($bid->shipping_cost ?? '0.0000'),
                supplierRatingScore: $ratings[(string) $bid->supplier_id] ?? 75.0,
                lines: $lineInputs,
            );
        }

        $scoringResult = $this->scoring->score($rfqLineIds, $inputs, $rfq->weights());

        $bidsById = $evaluatableBids->keyBy('id');
        $columns = collect($scoringResult->bidsById)
            ->map(function (BidScoreResult $scoreResult) use ($bidsById, $ratings, $scoringResult): array {
                /** @var SupplierBid $bid */
                $bid = $bidsById->get($scoreResult->bidId);

                return [
                    'bid_id' => (string) $bid->id,
                    'supplier_id' => (string) $bid->supplier_id,
                    'supplier_name' => (string) ($bid->supplier->company_name ?? ''),
                    'supplier_code' => (string) ($bid->supplier->supplier_code ?? ''),
                    'supplier_rating_grade' => $bid->supplier?->rating,
                    'supplier_rating_score' => $ratings[(string) $bid->supplier_id] ?? 75.0,
                    'quote_reference' => $bid->quote_reference,
                    'currency_code' => (string) $bid->currency,
                    'exchange_rate_to_rfq' => (string) $bid->exchange_rate_to_rfq,
                    'payment_terms_days' => (int) $bid->payment_terms_days,
                    'incoterms' => $bid->incoterms,
                    'shipping_cost' => (string) $bid->shipping_cost,
                    'valid_until' => $bid->valid_until?->toDateString(),
                    'general_notes' => $bid->notes,
                    'total_net_amount' => $scoreResult->totalNetAmount,
                    'total_landed_cost' => $scoreResult->totalLandedCost,
                    'normalized_landed_cost' => $scoreResult->normalizedLandedCost,
                    'weighted_overall_score' => $scoreResult->weightedOverallScore,
                    'quoted_line_count' => $scoreResult->quotedLineCount,
                    'covers_all_lines' => $scoreResult->coversAllLines,
                    'is_lowest_total_cost' => $scoringResult->lowestTotalCostBidId === (string) $bid->id,
                    'is_highest_score' => $scoringResult->highestScoreBidId === (string) $bid->id,
                    'status' => (string) $bid->status,
                    'submitted_at' => $bid->submitted_at?->toIso8601String(),
                ];
            })
            ->sort(static function (array $a, array $b): int {
                $scoreCmp = Decimal::cmp($b['weighted_overall_score'], $a['weighted_overall_score'], 2);

                return $scoreCmp !== 0 ? $scoreCmp : Decimal::cmp($a['normalized_landed_cost'], $b['normalized_landed_cost'], 4);
            })
            ->values()
            ->all();

        $bidLinesLookup = [];
        foreach ($evaluatableBids as $bid) {
            foreach ($bid->lines as $bidLine) {
                $bidLinesLookup[(string) $bid->id][(string) $bidLine->rfq_line_id] = $bidLine;
            }
        }

        $optimalSplit = [];
        $lowestPriceSplit = [];
        $rows = [];

        foreach ($rfq->lines as $rfqLine) {
            /** @var RfqLine $rfqLine */
            $rfqLineId = (string) $rfqLine->id;
            $cells = [];
            $bestPriceCell = null;
            $bestScoreCell = null;

            foreach ($columns as $column) {
                $bidId = $column['bid_id'];
                /** @var BidScoreResult|null $bidScore */
                $bidScore = $scoringResult->bidsById[$bidId] ?? null;
                /** @var LineScoreResult|null $lineScore */
                $lineScore = $bidScore?->linesByRfqLineId[$rfqLineId] ?? null;
                /** @var SupplierBidLine|null $bidLine */
                $bidLine = $bidLinesLookup[$bidId][$rfqLineId] ?? null;

                if ($lineScore === null || $bidLine === null) {
                    $cells[] = [
                        'bid_id' => $bidId,
                        'supplier_id' => $column['supplier_id'],
                        'supplier_bid_line_id' => null,
                        'is_no_bid' => true,
                        'quoted_quantity' => null,
                        'unit_price' => null,
                        'discount_percent' => null,
                        'net_unit_price' => null,
                        'line_total_net' => null,
                        'normalized_net_unit_price' => null,
                        'normalized_line_total_net' => null,
                        'lead_time_days' => null,
                        'promised_delivery_date' => null,
                        'supplier_notes' => null,
                        'price_score' => null,
                        'lead_time_score' => null,
                        'supplier_rating_score' => null,
                        'line_weighted_score' => null,
                        'is_lowest_price' => false,
                        'is_fastest_delivery' => false,
                        'is_highest_score' => false,
                        'is_awarded' => false,
                    ];

                    continue;
                }

                $cell = [
                    'bid_id' => $bidId,
                    'supplier_id' => $column['supplier_id'],
                    'supplier_bid_line_id' => (string) $bidLine->id,
                    'is_no_bid' => $lineScore->isNoBid,
                    'quoted_quantity' => (string) $bidLine->quantity,
                    'unit_price' => (string) $bidLine->unit_price,
                    'discount_percent' => (string) $bidLine->discount_percent,
                    'net_unit_price' => $lineScore->netUnitPrice,
                    'line_total_net' => $lineScore->lineTotalNet,
                    'normalized_net_unit_price' => $lineScore->normalizedNetUnitPrice,
                    'normalized_line_total_net' => $lineScore->normalizedLineTotalNet,
                    'lead_time_days' => $lineScore->leadTimeDays,
                    'promised_delivery_date' => $bidLine->promised_delivery_date?->toDateString(),
                    'supplier_notes' => $bidLine->supplier_notes,
                    'price_score' => $lineScore->priceScore,
                    'lead_time_score' => $lineScore->leadTimeScore,
                    'supplier_rating_score' => $lineScore->supplierRatingScore,
                    'line_weighted_score' => $lineScore->lineWeightedScore,
                    'is_lowest_price' => $lineScore->isLowestPrice,
                    'is_fastest_delivery' => $lineScore->isFastestDelivery,
                    'is_highest_score' => $lineScore->isHighestScore,
                    'is_awarded' => (bool) $bidLine->is_awarded,
                ];

                $cells[] = $cell;

                if (! $lineScore->isNoBid) {
                    if ($lineScore->isLowestPrice && $bestPriceCell === null) {
                        $bestPriceCell = $cell;
                    }
                    if ($lineScore->isHighestScore && $bestScoreCell === null) {
                        $bestScoreCell = $cell;
                    }
                }
            }

            if ($bestScoreCell !== null) {
                $optimalSplit[] = [
                    'rfq_line_id' => $rfqLineId,
                    'supplier_bid_line_id' => $bestScoreCell['supplier_bid_line_id'],
                    'supplier_id' => $bestScoreCell['supplier_id'],
                    'bid_id' => $bestScoreCell['bid_id'],
                ];
            }

            if ($bestPriceCell !== null) {
                $lowestPriceSplit[] = [
                    'rfq_line_id' => $rfqLineId,
                    'supplier_bid_line_id' => $bestPriceCell['supplier_bid_line_id'],
                    'supplier_id' => $bestPriceCell['supplier_id'],
                    'bid_id' => $bestPriceCell['bid_id'],
                ];
            }

            $rows[] = [
                'rfq_line_id' => $rfqLineId,
                'line_number' => (int) $rfqLine->line_number,
                'item_id' => $rfqLine->item_id,
                'custom_item_code' => $rfqLine->custom_item_code ?? $rfqLine->internal_sku,
                'description' => (string) $rfqLine->description,
                'technical_specifications' => $rfqLine->technical_specifications,
                'quantity' => (string) $rfqLine->quantity,
                'unit_of_measure' => (string) $rfqLine->uom,
                'target_unit_price' => $rfqLine->target_unit_price !== null ? (string) $rfqLine->target_unit_price : null,
                'requested_delivery_date' => $rfqLine->required_by_date?->toDateString(),
                'awarded_supplier_id' => $rfqLine->awarded_supplier_id,
                'awarded_bid_line_id' => $rfqLine->awarded_bid_line_id,
                'generated_purchase_order_id' => $rfqLine->generated_purchase_order_id,
                'best_unit_price' => $bestPriceCell['normalized_net_unit_price'] ?? null,
                'recommended_bid_line_id' => $bestScoreCell['supplier_bid_line_id'] ?? null,
                'cells' => $cells,
            ];
        }

        return [
            'rfq_id' => (string) $rfq->id,
            'rfq_number' => (string) $rfq->rfq_number,
            'title' => (string) $rfq->title,
            'status' => (string) $rfq->status,
            'currency_code' => $rfq->effectiveCurrencyCode(),
            'submission_deadline' => $rfq->effectiveDeadline()->toIso8601String(),
            'sealed_bidding' => (bool) $rfq->sealed_bidding,
            'is_sealed' => false,
            'can_award' => $rfq->statusEnum()->canBeAwarded() && $columns !== [],
            'evaluation_weights' => $rfq->weights()->toArray(),
            'invitations' => $this->mapInvitations($rfq, $ratings),
            'columns' => $columns,
            'rows' => $rows,
            'recommendations' => [
                'lowest_total_cost_bid_id' => $scoringResult->lowestTotalCostBidId,
                'highest_score_bid_id' => $scoringResult->highestScoreBidId,
                'optimal_split_line_awards' => $optimalSplit,
                'lowest_price_split_line_awards' => $lowestPriceSplit,
            ],
        ];
    }

    /**
     * @param  list<SupplierBid>  $evaluatableBids
     * @param  array<string, float>  $ratings
     * @return array<string, mixed>
     */
    private function sealedMatrix(RequestForQuotation $rfq, array $evaluatableBids, array $ratings): array
    {
        $bidsBySupplier = collect($evaluatableBids)->keyBy('supplier_id');

        $columns = $rfq->invitations->map(static function (RfqSupplierInvitation $invitation) use ($bidsBySupplier, $ratings): array {
            /** @var SupplierBid|null $bid */
            $bid = $bidsBySupplier->get((string) $invitation->supplier_id);

            return [
                'bid_id' => $bid !== null ? (string) $bid->id : null,
                'supplier_id' => (string) $invitation->supplier_id,
                'supplier_name' => (string) ($invitation->supplier->company_name ?? ''),
                'supplier_code' => (string) ($invitation->supplier->supplier_code ?? ''),
                'supplier_rating_grade' => $invitation->supplier?->rating,
                'supplier_rating_score' => $ratings[(string) $invitation->supplier_id] ?? 75.0,
                'quote_reference' => null,
                'currency_code' => null,
                'exchange_rate_to_rfq' => null,
                'payment_terms_days' => null,
                'incoterms' => null,
                'shipping_cost' => null,
                'valid_until' => null,
                'general_notes' => null,
                'total_net_amount' => null,
                'total_landed_cost' => null,
                'normalized_landed_cost' => null,
                'weighted_overall_score' => null,
                'quoted_line_count' => $bid !== null ? $bid->lines->where('is_no_bid', false)->count() : 0,
                'covers_all_lines' => false,
                'is_lowest_total_cost' => false,
                'is_highest_score' => false,
                'status' => (string) $invitation->status,
                'submitted_at' => $bid?->submitted_at?->toIso8601String(),
                'is_redacted' => true,
            ];
        })->values()->all();

        $rows = $rfq->lines->map(static fn (RfqLine $line): array => [
            'rfq_line_id' => (string) $line->id,
            'line_number' => (int) $line->line_number,
            'item_id' => $line->item_id,
            'custom_item_code' => $line->custom_item_code ?? $line->internal_sku,
            'description' => (string) $line->description,
            'technical_specifications' => $line->technical_specifications,
            'quantity' => (string) $line->quantity,
            'unit_of_measure' => (string) $line->uom,
            'target_unit_price' => $line->target_unit_price !== null ? (string) $line->target_unit_price : null,
            'requested_delivery_date' => $line->required_by_date?->toDateString(),
            'awarded_supplier_id' => null,
            'awarded_bid_line_id' => null,
            'generated_purchase_order_id' => null,
            'best_unit_price' => null,
            'recommended_bid_line_id' => null,
            'cells' => [],
        ])->values()->all();

        return [
            'rfq_id' => (string) $rfq->id,
            'rfq_number' => (string) $rfq->rfq_number,
            'title' => (string) $rfq->title,
            'status' => (string) $rfq->status,
            'currency_code' => $rfq->effectiveCurrencyCode(),
            'submission_deadline' => $rfq->effectiveDeadline()->toIso8601String(),
            'sealed_bidding' => true,
            'is_sealed' => true,
            'can_award' => false,
            'evaluation_weights' => $rfq->weights()->toArray(),
            'invitations' => $this->mapInvitations($rfq, $ratings),
            'columns' => $columns,
            'rows' => $rows,
            'recommendations' => [
                'lowest_total_cost_bid_id' => null,
                'highest_score_bid_id' => null,
                'optimal_split_line_awards' => [],
                'lowest_price_split_line_awards' => [],
            ],
        ];
    }

    /**
     * @param  array<string, float>  $ratings
     * @return list<array<string, mixed>>
     */
    private function mapInvitations(RequestForQuotation $rfq, array $ratings): array
    {
        return $rfq->invitations->map(static fn (RfqSupplierInvitation $invitation): array => [
            'id' => (string) $invitation->id,
            'supplier_id' => (string) $invitation->supplier_id,
            'supplier_name' => (string) ($invitation->supplier->company_name ?? ''),
            'supplier_rating_score' => $ratings[(string) $invitation->supplier_id] ?? 75.0,
            'status' => (string) $invitation->status,
            'invited_at' => $invitation->invited_at?->toIso8601String(),
            'first_viewed_at' => $invitation->first_viewed_at?->toIso8601String(),
            'submitted_at' => $invitation->submitted_at?->toIso8601String(),
            'declined_reason' => $invitation->declined_reason,
            'notified_outcome_at' => $invitation->notified_outcome_at?->toIso8601String(),
        ])->values()->all();
    }
}
