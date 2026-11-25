<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Modules\GdoTradeTerms\Domain\Enums\PromotionType;
use Modules\GdoTradeTerms\Domain\Enums\Treatment;
use Modules\GdoTradeTerms\Support\Money;
use Modules\GdoTradeTerms\Support\NotNull;
use Modules\GdoTradeTerms\Support\ScopeOverlap;

it('rounds half away from zero and never works with floats', function (): void {
    expect(Money::round('1.00005'))->toBe('1.0001')
        ->and(Money::round('-1.00005'))->toBe('-1.0001')
        ->and(Money::round('2.345', 2))->toBe('2.35')
        ->and(Money::from(1.5))->toBe('1.5000')
        ->and(Money::from(null))->toBe('0.0000')
        ->and(Money::from('1234.5'))->toBe('1234.5000')
        ->and(Money::sum(['10.10', 20, 0.05, null]))->toBe('30.1500');
});

it('combines the on-invoice discounts as a cascade, not as a sum', function (): void {
    // 1 - 0.97 x 0.98 x 0.985 x 0.99
    expect(Money::cascadeDiscount(['3', '2', '1.5', '1']))->toBe('7.3022')
        ->and(Money::cascadeDiscount(['0', '0', '0', '0']))->toBe('0.0000')
        ->and(Money::cascadeDiscount(['10', '10']))->toBe('19.0000');
});

it('computes percentages, signs and comparisons on decimals', function (): void {
    expect(Money::percentOf('250000', '2.25'))->toBe('5625.0000')
        ->and(Money::isNegative('-0.0001'))->toBeTrue()
        ->and(Money::isZero('0.00004'))->toBeTrue()
        ->and(Money::max('1', '2'))->toBe('2.0000')
        ->and(Money::min('1', '2'))->toBe('1.0000')
        ->and(Money::abs('-3.5'))->toBe('3.5000');
});

it('detects two scopes that count the same sale', function (): void {
    $jan = [Carbon::parse('2028-01-01'), Carbon::parse('2028-06-30')];
    $jul = [Carbon::parse('2028-07-01'), Carbon::parse('2028-12-31')];

    expect(ScopeOverlap::overlaps($jan[0], $jan[1], [], $jul[0], $jul[1], []))->toBeFalse('different windows')
        ->and(ScopeOverlap::overlaps($jan[0], $jan[1], [], $jan[0], $jan[1], []))->toBeTrue('both unrestricted')
        ->and(ScopeOverlap::overlaps($jan[0], $jan[1], ['brand_ids' => ['a']], $jan[0], $jan[1], ['brand_ids' => ['b']]))->toBeFalse('different brands')
        ->and(ScopeOverlap::overlaps($jan[0], $jan[1], ['brand_ids' => ['a', 'b']], $jan[0], $jan[1], ['brand_ids' => ['b']]))->toBeTrue('shared brand')
        ->and(ScopeOverlap::overlaps($jan[0], $jan[1], ['brand_ids' => ['a']], $jan[0], $jan[1], ['category_ids' => ['x']]))->toBeTrue('different dimensions, one unrestricted each')
        ->and(ScopeOverlap::overlaps($jan[0], $jan[1], ['delivery_point_ids' => ['s1']], $jan[0], $jan[1], ['delivery_point_ids' => ['s2']]))->toBeFalse('different stores')
        ->and(ScopeOverlap::overlaps($jan[0], $jul[1], [], $jul[0], $jul[1], []))->toBeTrue('window contains the other');
});

it('lets a form leave a NOT NULL column at its default', function (): void {
    expect(NotNull::strip(['a' => null, 'b' => null, 'c' => 1], ['a']))->toBe(['b' => null, 'c' => 1]);
});

it('settles a flyer with a credit note and every service of the retailer with its invoice', function (): void {
    expect(PromotionType::SellInFlyer->treatment())->toBe(Treatment::CreditNote);

    foreach ([PromotionType::SellOut, PromotionType::NewStoreOpening, PromotionType::ListingFee, PromotionType::Display, PromotionType::CediLogistics] as $type) {
        expect($type->treatment())->toBe(Treatment::PromoInvoice, $type->value);
    }

    expect(PromotionType::SellOut->readsSellOut())->toBeTrue()->and(PromotionType::Display->readsSellOut())->toBeFalse();
});
