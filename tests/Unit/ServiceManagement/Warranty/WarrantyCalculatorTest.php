<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\ServiceManagement\Enums\WarrantyPolicyType;
use Modules\ServiceManagement\Warranty\ExtendedCarePackExtension;
use Modules\ServiceManagement\Warranty\WarrantyCalculator;
use Modules\ServiceManagement\Warranty\WarrantyContext;
use Modules\ServiceManagement\Warranty\WarrantyResult;

test('calculator picks the strategy by jurisdiction', function () {
    $calculator = new WarrantyCalculator;
    $delivery = CarbonImmutable::parse('2025-03-10');

    expect($calculator->calculate(new WarrantyContext('CH', 'b2b', $delivery))->policyType)->toBe(WarrantyPolicyType::StatutoryCh24m)
        ->and($calculator->calculate(new WarrantyContext('IT', 'b2b', $delivery))->policyType)->toBe(WarrantyPolicyType::StatutoryItB2b12m)
        ->and($calculator->calculate(new WarrantyContext('IT', 'b2c', $delivery))->policyType)->toBe(WarrantyPolicyType::StatutoryItB2c24m);
});

test('an active care pack ending after the statutory expiry becomes the effective expiry', function () {
    $delivery = CarbonImmutable::parse('2025-03-10');
    $pack = ExtendedCarePackExtension::fromStart('Care Pack 12M', 12, CarbonImmutable::parse('2027-03-10'));

    $result = (new WarrantyCalculator)->calculate(new WarrantyContext('CH', 'b2c', $delivery, extensions: [$pack]));

    expect($result->expiryDate->toDateString())->toBe('2028-03-10')
        ->and($result->policyType)->toBe(WarrantyPolicyType::ExtendedCarePack)
        ->and($result->source)->toBe(WarrantyResult::SOURCE_EXTENSION)
        ->and($result->months)->toBe(24);
});

test('a care pack ending before the statutory expiry does not shorten the warranty', function () {
    $delivery = CarbonImmutable::parse('2025-03-10');
    $pack = ExtendedCarePackExtension::fromStart('Short pack', 6, CarbonImmutable::parse('2025-04-01'));

    $result = (new WarrantyCalculator)->calculate(new WarrantyContext('CH', 'b2c', $delivery, extensions: [$pack]));

    expect($result->expiryDate->toDateString())->toBe('2027-03-10')
        ->and($result->policyType)->toBe(WarrantyPolicyType::StatutoryCh24m);
});

test('a care pack ending before delivery is rejected', function () {
    $delivery = CarbonImmutable::parse('2025-03-10');
    $pack = ExtendedCarePackExtension::fromStart('Past pack', 1, CarbonImmutable::parse('2024-01-01'));

    (new WarrantyCalculator)->calculate(new WarrantyContext('IT', 'b2c', $delivery, extensions: [$pack]));
})->throws(InvalidArgumentException::class);

test('unknown jurisdictions are rejected', function () {
    (new WarrantyCalculator)->calculate(new WarrantyContext('DE', 'b2c', CarbonImmutable::parse('2025-03-10')));
})->throws(InvalidArgumentException::class);

test('the calculation is deterministic and the evaluation reports days remaining', function () {
    $calculator = new WarrantyCalculator;
    $context = new WarrantyContext('CH', 'b2c', CarbonImmutable::parse('2025-03-10'));

    $first = $calculator->calculate($context);
    $second = $calculator->calculate($context);

    expect($first->expiryDate->equalTo($second->expiryDate))->toBeTrue()
        ->and($first->evaluationAt(CarbonImmutable::parse('2026-11-15'))['days_remaining'])->toBe(115)
        ->and($first->evaluationAt(CarbonImmutable::parse('2027-03-10'))['status'])->toBe('in_warranty')
        ->and($first->evaluationAt(CarbonImmutable::parse('2027-03-11'))['status'])->toBe('out_of_warranty')
        ->and($first->evaluationAt(CarbonImmutable::parse('2027-03-11'))['days_remaining'])->toBe(0);
});
