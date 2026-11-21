<?php

declare(strict_types=1);

namespace App\Domain\Procurement\Rfq\Services;

use App\Domain\Procurement\Rfq\ValueObjects\BidHeaderInput;
use App\Domain\Procurement\Rfq\ValueObjects\BidScoreResult;
use App\Domain\Procurement\Rfq\ValueObjects\EvaluationWeights;
use App\Domain\Procurement\Rfq\ValueObjects\LineScoreResult;
use App\Domain\Procurement\Rfq\ValueObjects\ScoringMatrixResult;
use Modules\Procurement\Support\Decimal;

/**
 * Pure deterministic domain service computing normalized scores (0.00 - 100.00) per line and overall
 * across all submitted supplier bids, with multi-currency normalization and safe edge-case handling.
 */
final class BidScoringDomainService
{
    /**
     * @param  list<string>  $rfqLineIds
     * @param  list<BidHeaderInput>  $bids
     */
    public function score(array $rfqLineIds, array $bids, EvaluationWeights $weights): ScoringMatrixResult
    {
        $totalRfqLineCount = count($rfqLineIds);

        if ($bids === [] || $totalRfqLineCount === 0) {
            return new ScoringMatrixResult(bidsById: [], lowestTotalCostBidId: null, highestScoreBidId: null);
        }

        $rfqLineLookup = array_flip($rfqLineIds);
        $preparedBids = [];
        $minUnitPriceByLine = [];
        $minLeadTimeByLine = [];

        foreach ($bids as $bid) {
            $exchangeRate = Decimal::of($bid->exchangeRateToRfq, 6);
            if (Decimal::cmp($exchangeRate, '0', 6) <= 0) {
                $exchangeRate = '1.000000';
            }

            $shippingCost = Decimal::of($bid->shippingCost, 4);
            $normalizedShipping = Decimal::mul($shippingCost, $exchangeRate, 4);
            $totalNet = '0.0000';
            $normalizedTotalNet = '0.0000';
            $preparedLines = [];
            $quotedLineCount = 0;

            foreach ($bid->lines as $line) {
                if (! isset($rfqLineLookup[$line->rfqLineId])) {
                    continue;
                }

                if ($line->isNoBid) {
                    $preparedLines[$line->rfqLineId] = [
                        'line' => $line,
                        'net_unit_price' => '0.0000',
                        'line_total_net' => '0.0000',
                        'normalized_net_unit_price' => '0.0000',
                        'normalized_line_total_net' => '0.0000',
                        'lead_time_days' => max(0, $line->leadTimeDays),
                    ];

                    continue;
                }

                $quotedLineCount++;
                $qty = Decimal::of($line->quotedQuantity, 4);
                $unitPrice = Decimal::of($line->unitPrice, 4);
                $discount = Decimal::of($line->discountPercent, 2);
                $discountFactor = bcsub('1', bcdiv($discount, '100', 8), 8);
                $netUnitPrice = Decimal::mul($unitPrice, $discountFactor, 4);
                $lineTotalNet = Decimal::mul($netUnitPrice, $qty, 4);
                $normalizedNetUnitPrice = Decimal::mul($netUnitPrice, $exchangeRate, 4);
                $normalizedLineTotalNet = Decimal::mul($lineTotalNet, $exchangeRate, 4);
                $leadTime = max(0, $line->leadTimeDays);

                $totalNet = Decimal::add($totalNet, $lineTotalNet, 4);
                $normalizedTotalNet = Decimal::add($normalizedTotalNet, $normalizedLineTotalNet, 4);

                $preparedLines[$line->rfqLineId] = [
                    'line' => $line,
                    'net_unit_price' => $netUnitPrice,
                    'line_total_net' => $lineTotalNet,
                    'normalized_net_unit_price' => $normalizedNetUnitPrice,
                    'normalized_line_total_net' => $normalizedLineTotalNet,
                    'lead_time_days' => $leadTime,
                ];

                if (! isset($minUnitPriceByLine[$line->rfqLineId]) || Decimal::cmp($normalizedNetUnitPrice, $minUnitPriceByLine[$line->rfqLineId], 4) < 0) {
                    $minUnitPriceByLine[$line->rfqLineId] = $normalizedNetUnitPrice;
                }

                if (! isset($minLeadTimeByLine[$line->rfqLineId]) || $leadTime < $minLeadTimeByLine[$line->rfqLineId]) {
                    $minLeadTimeByLine[$line->rfqLineId] = $leadTime;
                }
            }

            $totalLandedCost = Decimal::add($totalNet, $shippingCost, 4);
            $normalizedLandedCost = Decimal::add($normalizedTotalNet, $normalizedShipping, 4);
            $coversAllLines = $quotedLineCount === $totalRfqLineCount;

            $preparedBids[$bid->bidId] = [
                'bid' => $bid,
                'total_net_amount' => $totalNet,
                'total_landed_cost' => $totalLandedCost,
                'normalized_landed_cost' => $normalizedLandedCost,
                'quoted_line_count' => $quotedLineCount,
                'covers_all_lines' => $coversAllLines,
                'lines' => $preparedLines,
            ];
        }

        $maxWeightedScoreByLine = [];
        $intermediateLineScores = [];

        foreach ($preparedBids as $bidId => $bidData) {
            /** @var BidHeaderInput $bid */
            $bid = $bidData['bid'];
            $ratingScore = $this->clampRating($bid->supplierRatingScore);

            foreach ($bidData['lines'] as $rfqLineId => $lineData) {
                $line = $lineData['line'];

                if ($line->isNoBid) {
                    $intermediateLineScores[$bidId][$rfqLineId] = [
                        'price_score' => '0.00',
                        'lead_time_score' => '0.00',
                        'rating_score' => $ratingScore,
                        'weighted_score' => '0.00',
                    ];

                    continue;
                }

                $minPrice = $minUnitPriceByLine[$rfqLineId] ?? '0.0000';
                $priceScore = $this->computePriceScore($minPrice, $lineData['normalized_net_unit_price']);

                $minLeadTime = $minLeadTimeByLine[$rfqLineId] ?? 0;
                $leadTimeScore = $this->computeLeadTimeScore($minLeadTime, $lineData['lead_time_days']);

                $lineWeightedScore = $this->combineWeightedScore($priceScore, $leadTimeScore, $ratingScore, $weights);

                $intermediateLineScores[$bidId][$rfqLineId] = [
                    'price_score' => $priceScore,
                    'lead_time_score' => $leadTimeScore,
                    'rating_score' => $ratingScore,
                    'weighted_score' => $lineWeightedScore,
                ];

                if (! isset($maxWeightedScoreByLine[$rfqLineId]) || Decimal::cmp($lineWeightedScore, $maxWeightedScoreByLine[$rfqLineId], 2) > 0) {
                    $maxWeightedScoreByLine[$rfqLineId] = $lineWeightedScore;
                }
            }
        }

        // Minimum normalized landed cost among full-coverage bids (fallback to any bid with >= 1 quoted line)
        $minFullLandedCost = null;
        foreach ($preparedBids as $bidData) {
            if ($bidData['covers_all_lines']) {
                $landed = $bidData['normalized_landed_cost'];
                if ($minFullLandedCost === null || Decimal::cmp($landed, $minFullLandedCost, 4) < 0) {
                    $minFullLandedCost = $landed;
                }
            }
        }

        $bidResults = [];
        $lowestTotalCostBidId = null;
        $lowestTotalCostValue = null;
        $lowestFullCostBidId = null;
        $lowestFullCostValue = null;
        $highestFullScoreBidId = null;
        $highestFullScoreValue = null;
        $highestAnyScoreBidId = null;
        $highestAnyScoreValue = null;

        foreach ($preparedBids as $bidId => $bidData) {
            /** @var BidHeaderInput $bid */
            $bid = $bidData['bid'];
            $ratingScore = $this->clampRating($bid->supplierRatingScore);
            $lineResults = [];
            $sumLinePriceScore = '0.00';
            $sumLineLeadTimeScore = '0.00';

            foreach ($bidData['lines'] as $rfqLineId => $lineData) {
                $line = $lineData['line'];
                $scores = $intermediateLineScores[$bidId][$rfqLineId];

                if (! $line->isNoBid) {
                    $sumLinePriceScore = Decimal::add($sumLinePriceScore, $scores['price_score'], 4);
                    $sumLineLeadTimeScore = Decimal::add($sumLineLeadTimeScore, $scores['lead_time_score'], 4);
                }

                $isLowestPrice = ! $line->isNoBid
                    && isset($minUnitPriceByLine[$rfqLineId])
                    && Decimal::cmp($lineData['normalized_net_unit_price'], $minUnitPriceByLine[$rfqLineId], 4) === 0;

                $isFastestDelivery = ! $line->isNoBid
                    && isset($minLeadTimeByLine[$rfqLineId])
                    && $lineData['lead_time_days'] === $minLeadTimeByLine[$rfqLineId];

                $isHighestScore = ! $line->isNoBid
                    && isset($maxWeightedScoreByLine[$rfqLineId])
                    && Decimal::cmp($scores['weighted_score'], $maxWeightedScoreByLine[$rfqLineId], 2) === 0;

                $lineResults[$rfqLineId] = new LineScoreResult(
                    bidId: $bidId,
                    bidLineId: $line->bidLineId,
                    rfqLineId: $rfqLineId,
                    isNoBid: $line->isNoBid,
                    netUnitPrice: $lineData['net_unit_price'],
                    lineTotalNet: $lineData['line_total_net'],
                    normalizedNetUnitPrice: $lineData['normalized_net_unit_price'],
                    normalizedLineTotalNet: $lineData['normalized_line_total_net'],
                    leadTimeDays: $lineData['lead_time_days'],
                    priceScore: $scores['price_score'],
                    leadTimeScore: $scores['lead_time_score'],
                    supplierRatingScore: $scores['rating_score'],
                    lineWeightedScore: $scores['weighted_score'],
                    isLowestPrice: $isLowestPrice,
                    isFastestDelivery: $isFastestDelivery,
                    isHighestScore: $isHighestScore,
                );
            }

            if ($bidData['quoted_line_count'] === 0) {
                $weightedOverallScore = '0.00';
            } else {
                $overallPriceScore = $bidData['covers_all_lines'] && $minFullLandedCost !== null
                    ? $this->computePriceScore($minFullLandedCost, $bidData['normalized_landed_cost'])
                    : Decimal::div($sumLinePriceScore, (string) $totalRfqLineCount, 2);

                $overallLeadTimeScore = Decimal::div($sumLineLeadTimeScore, (string) $totalRfqLineCount, 2);
                $weightedOverallScore = $this->combineWeightedScore($overallPriceScore, $overallLeadTimeScore, $ratingScore, $weights);
            }

            $bidResults[$bidId] = new BidScoreResult(
                bidId: $bidId,
                supplierId: $bid->supplierId,
                totalNetAmount: $bidData['total_net_amount'],
                totalLandedCost: $bidData['total_landed_cost'],
                normalizedLandedCost: $bidData['normalized_landed_cost'],
                weightedOverallScore: $weightedOverallScore,
                quotedLineCount: $bidData['quoted_line_count'],
                coversAllLines: $bidData['covers_all_lines'],
                linesByRfqLineId: $lineResults,
            );

            if ($bidData['quoted_line_count'] > 0) {
                $landed = $bidData['normalized_landed_cost'];

                if ($bidData['covers_all_lines']) {
                    if ($lowestFullCostValue === null || Decimal::cmp($landed, $lowestFullCostValue, 4) < 0) {
                        $lowestFullCostValue = $landed;
                        $lowestFullCostBidId = $bidId;
                    }

                    if ($highestFullScoreValue === null
                        || Decimal::cmp($weightedOverallScore, $highestFullScoreValue, 2) > 0
                        || (Decimal::cmp($weightedOverallScore, $highestFullScoreValue, 2) === 0 && Decimal::cmp($landed, $bidResults[$highestFullScoreBidId]->normalizedLandedCost, 4) < 0)) {
                        $highestFullScoreValue = $weightedOverallScore;
                        $highestFullScoreBidId = $bidId;
                    }
                }

                if ($lowestTotalCostValue === null || Decimal::cmp($landed, $lowestTotalCostValue, 4) < 0) {
                    $lowestTotalCostValue = $landed;
                    $lowestTotalCostBidId = $bidId;
                }

                if ($highestAnyScoreValue === null || Decimal::cmp($weightedOverallScore, $highestAnyScoreValue, 2) > 0) {
                    $highestAnyScoreValue = $weightedOverallScore;
                    $highestAnyScoreBidId = $bidId;
                }
            }
        }

        return new ScoringMatrixResult(
            bidsById: $bidResults,
            lowestTotalCostBidId: $lowestFullCostBidId ?? $lowestTotalCostBidId,
            highestScoreBidId: $highestFullScoreBidId ?? $highestAnyScoreBidId,
        );
    }

    private function computePriceScore(string $minPrice, string $supplierPrice): string
    {
        $minCmp = Decimal::cmp($minPrice, '0', 4);
        $supCmp = Decimal::cmp($supplierPrice, '0', 4);

        if ($minCmp <= 0 && $supCmp <= 0) {
            return '100.00';
        }

        if ($minCmp <= 0 || $supCmp <= 0) {
            return '0.00';
        }

        $score = Decimal::div(bcmul($minPrice, '100', 8), $supplierPrice, 2);

        return Decimal::cmp($score, '100.00', 2) > 0 ? '100.00' : $score;
    }

    private function computeLeadTimeScore(int $minLeadTime, int $supplierLeadTime): string
    {
        if ($supplierLeadTime <= 0) {
            return '100.00';
        }

        if ($minLeadTime <= 0) {
            return Decimal::div('100', (string) ($supplierLeadTime + 1), 2);
        }

        $score = Decimal::div(bcmul((string) $minLeadTime, '100', 8), (string) $supplierLeadTime, 2);

        return Decimal::cmp($score, '100.00', 2) > 0 ? '100.00' : $score;
    }

    private function clampRating(float $rating): string
    {
        $clamped = max(0.0, min(100.0, $rating));

        return Decimal::of($clamped, 2);
    }

    private function combineWeightedScore(
        string $priceScore,
        string $leadTimeScore,
        string $ratingScore,
        EvaluationWeights $weights,
    ): string {
        $pPart = bcmul($priceScore, (string) $weights->priceWeight, 6);
        $lPart = bcmul($leadTimeScore, (string) $weights->leadTimeWeight, 6);
        $rPart = bcmul($ratingScore, (string) $weights->supplierRatingWeight, 6);
        $sum = bcadd(bcadd($pPart, $lPart, 6), $rPart, 6);

        return Decimal::div($sum, '100', 2);
    }
}
