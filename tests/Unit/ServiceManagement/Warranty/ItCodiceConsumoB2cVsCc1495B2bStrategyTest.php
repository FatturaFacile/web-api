<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\ServiceManagement\Enums\WarrantyPolicyType;
use Modules\ServiceManagement\Warranty\ItCodiceConsumoB2cVsCc1495B2bStrategy;
use Modules\ServiceManagement\Warranty\WarrantyContext;
use Modules\ServiceManagement\Warranty\WarrantyResult;

test('IT strategy supports only the IT jurisdiction', function () {
    $strategy = new ItCodiceConsumoB2cVsCc1495B2bStrategy;
    $delivery = CarbonImmutable::parse('2025-03-10');

    expect($strategy->supports(new WarrantyContext('IT', 'b2b', $delivery)))->toBeTrue()
        ->and($strategy->supports(new WarrantyContext('CH', 'b2b', $delivery)))->toBeFalse();
});

test('IT B2C gets 24 months under the Codice del Consumo', function () {
    $result = (new ItCodiceConsumoB2cVsCc1495B2bStrategy)->calculate(
        new WarrantyContext('IT', 'b2c', CarbonImmutable::parse('2025-03-10')),
    );

    expect($result->expiryDate->toDateString())->toBe('2027-03-10')
        ->and($result->policyType)->toBe(WarrantyPolicyType::StatutoryItB2c24m)
        ->and($result->months)->toBe(24)
        ->and($result->source)->toBe(WarrantyResult::SOURCE_STATUTORY);
});

test('IT B2B gets 12 months under Art. 1495 c.c.', function () {
    $result = (new ItCodiceConsumoB2cVsCc1495B2bStrategy)->calculate(
        new WarrantyContext('IT', 'b2b', CarbonImmutable::parse('2025-03-10')),
    );

    expect($result->expiryDate->toDateString())->toBe('2026-03-10')
        ->and($result->policyType)->toBe(WarrantyPolicyType::StatutoryItB2b12m)
        ->and($result->months)->toBe(12);
});

test('IT B2C contract clause cannot go below the 24 months consumer floor', function () {
    $result = (new ItCodiceConsumoB2cVsCc1495B2bStrategy)->calculate(
        new WarrantyContext('IT', 'b2c', CarbonImmutable::parse('2025-03-10'), contractWarrantyMonths: 6),
    );

    expect($result->expiryDate->toDateString())->toBe('2027-03-10')
        ->and($result->source)->toBe(WarrantyResult::SOURCE_STATUTORY);
});

test('IT B2B contract clause overrides the statutory period', function () {
    $result = (new ItCodiceConsumoB2cVsCc1495B2bStrategy)->calculate(
        new WarrantyContext('IT', 'b2b', CarbonImmutable::parse('2025-03-10'), contractWarrantyMonths: 24),
    );

    expect($result->expiryDate->toDateString())->toBe('2027-03-10')
        ->and($result->source)->toBe(WarrantyResult::SOURCE_CONTRACT_CLAUSE);
});
