<?php

declare(strict_types=1);

use App\Domain\Procurement\Rfq\Services\BidScoringDomainService;
use App\Domain\Procurement\Rfq\ValueObjects\BidHeaderInput;
use App\Domain\Procurement\Rfq\ValueObjects\BidLineInput;
use App\Domain\Procurement\Rfq\ValueObjects\EvaluationWeights;
use Modules\Procurement\Exceptions\ProcurementRuleViolation;

test('evaluation weights must sum to 100 and stay within bounds', function (): void {
    $valid = EvaluationWeights::fromArray([
        'price_weight' => 50,
        'lead_time_weight' => 30,
        'supplier_rating_weight' => 20,
    ]);

    expect($valid->priceWeight)->toBe(50)
        ->and($valid->leadTimeWeight)->toBe(30)
        ->and($valid->supplierRatingWeight)->toBe(20);

    expect(fn () => new EvaluationWeights(60, 30, 20))->toThrow(ProcurementRuleViolation::class);
    expect(fn () => new EvaluationWeights(-10, 60, 50))->toThrow(ProcurementRuleViolation::class);
});

test('bid scoring normalizes prices, handles zero lead time, partial no-bid lines, and multi-currency conversion', function (): void {
    $service = new BidScoringDomainService;
    $weights = new EvaluationWeights(priceWeight: 60, leadTimeWeight: 25, supplierRatingWeight: 15);

    $rfqLines = ['line-1', 'line-2'];

    // Supplier A quotes in CHF (rate 1.05 to EUR), immediate delivery (0 days) on line-1, 10% discount, no-bid on line-2
    $bidA = new BidHeaderInput(
        bidId: 'bid-a',
        supplierId: 'sup-a',
        currencyCode: 'CHF',
        exchangeRateToRfq: '1.050000',
        shippingCost: '20.0000',
        supplierRatingScore: 90.0,
        lines: [
            new BidLineInput(
                bidLineId: 'bl-a1',
                rfqLineId: 'line-1',
                isNoBid: false,
                quotedQuantity: '10.0000',
                unitPrice: '100.0000',
                discountPercent: '10.00', // net unit = 90 CHF -> 94.50 EUR
                leadTimeDays: 0,
            ),
            new BidLineInput(
                bidLineId: 'bl-a2',
                rfqLineId: 'line-2',
                isNoBid: true,
                quotedQuantity: '5.0000',
                unitPrice: '0.0000',
                discountPercent: '0.00',
                leadTimeDays: 0,
            ),
        ],
    );

    // Supplier B quotes in EUR (rate 1.00), covers both lines, 5 days on line-1, 4 days on line-2
    $bidB = new BidHeaderInput(
        bidId: 'bid-b',
        supplierId: 'sup-b',
        currencyCode: 'EUR',
        exchangeRateToRfq: '1.000000',
        shippingCost: '10.0000',
        supplierRatingScore: 80.0,
        lines: [
            new BidLineInput(
                bidLineId: 'bl-b1',
                rfqLineId: 'line-1',
                isNoBid: false,
                quotedQuantity: '10.0000',
                unitPrice: '100.0000',
                discountPercent: '0.00', // net unit = 100 EUR
                leadTimeDays: 5,
            ),
            new BidLineInput(
                bidLineId: 'bl-b2',
                rfqLineId: 'line-2',
                isNoBid: false,
                quotedQuantity: '5.0000',
                unitPrice: '40.0000',
                discountPercent: '0.00',
                leadTimeDays: 4,
            ),
        ],
    );

    $result = $service->score($rfqLines, [$bidA, $bidB], $weights);

    $scoreA = $result->bidsById['bid-a'];
    $scoreB = $result->bidsById['bid-b'];

    // Bid A line 1: 94.50 EUR < 100.00 EUR, lead time 0 < 5 -> price_score=100, lead_time_score=100, rating=90 -> weighted=98.50
    $lineA1 = $scoreA->linesByRfqLineId['line-1'];
    $lineA2 = $scoreA->linesByRfqLineId['line-2'];
    $lineB1 = $scoreB->linesByRfqLineId['line-1'];

    expect($lineA1->netUnitPrice)->toBe('90.0000')
        ->and($lineA1->normalizedNetUnitPrice)->toBe('94.5000')
        ->and($lineA1->priceScore)->toBe('100.00')
        ->and($lineA1->leadTimeScore)->toBe('100.00')
        ->and($lineA1->lineWeightedScore)->toBe('98.50')
        ->and($lineA1->isLowestPrice)->toBeTrue()
        ->and($lineA1->isFastestDelivery)->toBeTrue()
        ->and($lineA1->isHighestScore)->toBeTrue();

    // Bid A line 2 is no-bid: flags must all be false and scores 0.00
    expect($lineA2->isNoBid)->toBeTrue()
        ->and($lineA2->lineWeightedScore)->toBe('0.00')
        ->and($lineA2->isLowestPrice)->toBeFalse()
        ->and($lineA2->isFastestDelivery)->toBeFalse()
        ->and($lineA2->isHighestScore)->toBeFalse();

    // Bid B line 1: price score = 94.50/100 * 100 = 94.50; minLeadTime is 0 so 100 / (5 + 1) = 16.67
    expect($lineB1->priceScore)->toBe('94.50')
        ->and($lineB1->leadTimeScore)->toBe('16.67')
        ->and($lineB1->isLowestPrice)->toBeFalse()
        ->and($lineB1->isFastestDelivery)->toBeFalse();

    // Bid B covers all lines, Bid A is partial -> full-coverage bid wins overall recommendation
    expect($scoreA->coversAllLines)->toBeFalse()
        ->and($scoreB->coversAllLines)->toBeTrue()
        ->and($scoreB->totalNetAmount)->toBe('1200.0000')
        ->and($scoreB->totalLandedCost)->toBe('1210.0000')
        ->and($result->lowestTotalCostBidId)->toBe('bid-b')
        ->and($result->highestScoreBidId)->toBe('bid-b');
});

test('bid scoring prefers lowest normalized landed cost and highest weighted score among full-coverage bids', function (): void {
    $service = new BidScoringDomainService;
    $weights = EvaluationWeights::default(); // 60 / 25 / 15

    $bidFast = new BidHeaderInput(
        bidId: 'bid-fast',
        supplierId: 'sup-fast',
        currencyCode: 'EUR',
        exchangeRateToRfq: '1.000000',
        shippingCost: '0.0000',
        supplierRatingScore: 95.0,
        lines: [
            new BidLineInput('l1', 'rfq-1', false, '10.0000', '105.0000', '0.00', 2),
        ],
    );

    $bidCheap = new BidHeaderInput(
        bidId: 'bid-cheap',
        supplierId: 'sup-cheap',
        currencyCode: 'EUR',
        exchangeRateToRfq: '1.000000',
        shippingCost: '0.0000',
        supplierRatingScore: 60.0,
        lines: [
            new BidLineInput('l2', 'rfq-1', false, '10.0000', '100.0000', '0.00', 20),
        ],
    );

    $result = $service->score(['rfq-1'], [$bidFast, $bidCheap], $weights);

    expect($result->lowestTotalCostBidId)->toBe('bid-cheap')
        ->and($result->highestScoreBidId)->toBe('bid-fast');
});
