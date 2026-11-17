<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\ServiceManagement\Enums\WarrantyPolicyType;
use Modules\ServiceManagement\Warranty\ChArt210CoTwoYearWarrantyStrategy;
use Modules\ServiceManagement\Warranty\WarrantyContext;
use Modules\ServiceManagement\Warranty\WarrantyResult;

test('CH strategy supports only the CH jurisdiction', function () {
    $strategy = new ChArt210CoTwoYearWarrantyStrategy;
    $delivery = CarbonImmutable::parse('2025-03-10');

    expect($strategy->supports(new WarrantyContext('CH', 'b2c', $delivery)))->toBeTrue()
        ->and($strategy->supports(new WarrantyContext('ch', 'b2c', $delivery)))->toBeTrue()
        ->and($strategy->supports(new WarrantyContext('IT', 'b2c', $delivery)))->toBeFalse();
});

test('CH B2C delivered 2025-03-10 is covered until 2027-03-10 under Art. 210 CO', function () {
    $result = (new ChArt210CoTwoYearWarrantyStrategy)->calculate(
        new WarrantyContext('CH', 'b2c', CarbonImmutable::parse('2025-03-10')),
    );

    expect($result->expiryDate->toDateString())->toBe('2027-03-10')
        ->and($result->startDate->toDateString())->toBe('2025-03-10')
        ->and($result->policyType)->toBe(WarrantyPolicyType::StatutoryCh24m)
        ->and($result->months)->toBe(24)
        ->and($result->source)->toBe(WarrantyResult::SOURCE_STATUTORY)
        ->and($result->isInWarrantyAt(CarbonImmutable::parse('2026-11-15')))->toBeTrue()
        ->and($result->evaluationAt(CarbonImmutable::parse('2026-11-15'))['status'])->toBe('in_warranty');
});

test('CH B2B without contract clause keeps the statutory 24 months', function () {
    $result = (new ChArt210CoTwoYearWarrantyStrategy)->calculate(
        new WarrantyContext('CH', 'b2b', CarbonImmutable::parse('2025-03-10')),
    );

    expect($result->expiryDate->toDateString())->toBe('2027-03-10')
        ->and($result->source)->toBe(WarrantyResult::SOURCE_STATUTORY);
});

test('CH B2B contract clause may shorten the warranty to 12 months', function () {
    $result = (new ChArt210CoTwoYearWarrantyStrategy)->calculate(
        new WarrantyContext('CH', 'b2b', CarbonImmutable::parse('2025-03-10'), contractWarrantyMonths: 12),
    );

    expect($result->expiryDate->toDateString())->toBe('2026-03-10')
        ->and($result->months)->toBe(12)
        ->and($result->source)->toBe(WarrantyResult::SOURCE_CONTRACT_CLAUSE)
        ->and($result->policyType)->toBe(WarrantyPolicyType::StatutoryCh24m);
});

test('CH B2C contract clause can extend but never shorten the consumer warranty', function () {
    $strategy = new ChArt210CoTwoYearWarrantyStrategy;
    $delivery = CarbonImmutable::parse('2025-03-10');

    $shorter = $strategy->calculate(new WarrantyContext('CH', 'b2c', $delivery, contractWarrantyMonths: 12));
    $longer = $strategy->calculate(new WarrantyContext('CH', 'b2c', $delivery, contractWarrantyMonths: 36));

    expect($shorter->expiryDate->toDateString())->toBe('2027-03-10')
        ->and($shorter->source)->toBe(WarrantyResult::SOURCE_STATUTORY)
        ->and($longer->expiryDate->toDateString())->toBe('2028-03-10')
        ->and($longer->source)->toBe(WarrantyResult::SOURCE_CONTRACT_CLAUSE);
});

test('month arithmetic clamps to the end of month instead of overflowing', function () {
    $result = (new ChArt210CoTwoYearWarrantyStrategy)->calculate(
        new WarrantyContext('CH', 'b2b', CarbonImmutable::parse('2024-01-31'), contractWarrantyMonths: 1),
    );

    expect($result->expiryDate->toDateString())->toBe('2024-02-29');
});
