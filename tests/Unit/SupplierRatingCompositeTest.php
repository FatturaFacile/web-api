<?php

declare(strict_types=1);

use Modules\Procurement\Enums\SupplierQualificationStatus;
use Modules\Procurement\Services\SupplierRatingService;

it('weights the five components to 100', function (): void {
    expect(array_sum(SupplierRatingService::Weights))->toBe(100);
});

it('computes the weighted composite score of all components', function (): void {
    $score = SupplierRatingService::composite([
        'on_time_delivery_rate' => '100.00',
        'quality_acceptance_rate' => '80.00',
        'price_competitiveness_score' => '60.00',
        'invoice_accuracy_rate' => '50.00',
        'rfq_responsiveness_rate' => '0.00',
    ]);

    // (100*35 + 80*30 + 60*15 + 50*10 + 0*10) / 100
    expect($score)->toBe('73.00');
});

it('renormalises the weights over the components that have data', function (): void {
    $score = SupplierRatingService::composite([
        'on_time_delivery_rate' => '90.00',
        'quality_acceptance_rate' => null,
        'price_competitiveness_score' => null,
        'invoice_accuracy_rate' => '100.00',
        'rfq_responsiveness_rate' => null,
    ]);

    // (90*35 + 100*10) / 45
    expect($score)->toBe('91.11');
});

it('has no score without any component', function (): void {
    expect(SupplierRatingService::composite(array_fill_keys(array_keys(SupplierRatingService::Weights), null)))->toBeNull();
});

it('classifies the score into the A to D tiers at their thresholds', function (string $score, string $tier): void {
    expect(SupplierRatingService::tier($score))->toBe($tier);
})->with([
    ['100.00', 'A'],
    ['90.00', 'A'],
    ['89.99', 'B'],
    ['75.00', 'B'],
    ['74.99', 'C'],
    ['60.00', 'C'],
    ['59.99', 'D'],
    ['0.00', 'D'],
]);

it('lets preferred suppliers order and keeps suspended and blacklisted ones out', function (): void {
    expect(SupplierQualificationStatus::Preferred->canOrder())->toBeTrue()
        ->and(SupplierQualificationStatus::Qualified->canOrder())->toBeTrue()
        ->and(SupplierQualificationStatus::Suspended->canOrder())->toBeFalse()
        ->and(SupplierQualificationStatus::Blacklisted->canOrder())->toBeFalse()
        ->and(SupplierQualificationStatus::values())->toContain('preferred', 'suspended', 'blacklisted');
});
