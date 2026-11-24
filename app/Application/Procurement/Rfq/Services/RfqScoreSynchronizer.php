<?php

declare(strict_types=1);

namespace App\Application\Procurement\Rfq\Services;

use App\Domain\Procurement\Rfq\Contracts\SupplierRatingResolverInterface;
use App\Domain\Procurement\Rfq\Models\RequestForQuotation;
use App\Domain\Procurement\Rfq\Models\SupplierBid;
use App\Domain\Procurement\Rfq\Models\SupplierBidLine;
use App\Domain\Procurement\Rfq\Services\BidScoringDomainService;
use App\Domain\Procurement\Rfq\ValueObjects\BidHeaderInput;
use App\Domain\Procurement\Rfq\ValueObjects\BidLineInput;
use App\Domain\Procurement\Rfq\ValueObjects\ScoringMatrixResult;
use Modules\Procurement\Support\Decimal;

/**
 * Coordinates BidScoringDomainService with persisted SupplierBid / SupplierBidLine records.
 */
final readonly class RfqScoreSynchronizer
{
    public function __construct(
        private BidScoringDomainService $scoring,
        private SupplierRatingResolverInterface $ratingResolver,
    ) {}

    public function synchronize(RequestForQuotation $rfq): ScoringMatrixResult
    {
        $rfq->load(['lines', 'bids.lines']);
        $rfqLineIds = $rfq->lines->pluck('id')->map(static fn (mixed $id): string => (string) $id)->values()->all();

        $evaluatableBids = $rfq->bids->filter(
            static fn (SupplierBid $bid): bool => $bid->statusEnum()->isEvaluatable()
        )->values();

        if ($evaluatableBids->isEmpty()) {
            return new ScoringMatrixResult(bidsById: [], lowestTotalCostBidId: null, highestScoreBidId: null);
        }

        $supplierIds = $evaluatableBids->pluck('supplier_id')->map(static fn (mixed $id): string => (string) $id)->unique()->values()->all();
        $ratings = $this->ratingResolver->resolveRatings($supplierIds);

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

        $matrix = $this->scoring->score($rfqLineIds, $inputs, $rfq->weights());

        foreach ($evaluatableBids as $bid) {
            /** @var SupplierBid $bid */
            $bidResult = $matrix->bidsById[(string) $bid->id] ?? null;
            if ($bidResult === null) {
                continue;
            }

            $bid->update([
                'total_net_amount' => $bidResult->totalNetAmount,
                'total_landed_cost' => $bidResult->totalLandedCost,
                'total_amount' => Decimal::round($bidResult->totalNetAmount, 2),
                'weighted_overall_score' => $bidResult->weightedOverallScore,
            ]);

            foreach ($bid->lines as $line) {
                /** @var SupplierBidLine $line */
                $lineResult = $bidResult->linesByRfqLineId[(string) $line->rfq_line_id] ?? null;
                if ($lineResult === null) {
                    continue;
                }

                $line->update([
                    'net_unit_price' => $lineResult->netUnitPrice,
                    'line_total_net' => $lineResult->lineTotalNet,
                    'line_total' => Decimal::round($lineResult->lineTotalNet, 2),
                    'line_weighted_score' => $lineResult->lineWeightedScore,
                ]);
            }
        }

        return $matrix;
    }
}
