<?php

declare(strict_types=1);

use Modules\ProductCatalog\Domain\Enums\KitType;
use Modules\ProductCatalog\Domain\Enums\ProductType;
use Modules\ProductCatalog\Domain\Enums\RoundingMode;
use Modules\ProductCatalog\Domain\Support\Dec;
use Modules\ProductCatalog\Domain\Support\Gtin;
use Modules\ProductCatalog\Domain\Support\SkuBuilder;
use Modules\ProductCatalog\Domain\Support\UomConverter;
use Modules\ProductCatalog\Domain\Support\VariantPricing;
use Modules\ProductCatalog\Exceptions\CatalogException;

it('does exact decimal arithmetic without floats', function (): void {
    expect(Dec::add('0.1', '0.2'))->toBe('0.300000000000')
        ->and(Dec::trim(Dec::mul('0.1', '3')))->toBe('0.3')
        ->and(Dec::trim(Dec::div('1', '8')))->toBe('0.125')
        ->and(Dec::cmp('1.0', '1'))->toBe(0)
        ->and(Dec::isZero('0.000'))->toBeTrue()
        ->and(Dec::trim('12.500000'))->toBe('12.5')
        ->and(Dec::trim('100'))->toBe('100');
});

it('rounds half up, up and down, also below zero', function (): void {
    expect(Dec::round('2.345', 2))->toBe('2.35')
        ->and(Dec::round('2.344', 2))->toBe('2.34')
        ->and(Dec::round('2.341', 2, 'up'))->toBe('2.35')
        ->and(Dec::round('2.349', 2, 'down'))->toBe('2.34')
        ->and(Dec::round('-2.345', 2))->toBe('-2.35')
        ->and(Dec::round('-0.001', 2, 'down'))->toBe('0.00')
        ->and(Dec::round('7', 0))->toBe('7');
});

it('knows whether a value fits a number of decimals', function (): void {
    expect(Dec::fits('1.25', 2))->toBeTrue()
        ->and(Dec::fits('1.255', 2))->toBeFalse()
        ->and(Dec::fits('3', 0))->toBeTrue();
});

it('refuses text that is not a decimal and a division by zero', function (): void {
    expect(fn () => Dec::of('abc'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Dec::div('1', '0'))->toThrow(InvalidArgumentException::class);
});

it('computes and validates GS1 check digits', function (): void {
    expect(Gtin::checkDigit('400638133393'))->toBe(1)
        ->and(Gtin::isValid('4006381333931'))->toBeTrue()
        ->and(Gtin::isValid('4006381333932'))->toBeFalse()
        ->and(Gtin::isValid('96385074'))->toBeTrue()
        ->and(Gtin::isValid('123'))->toBeFalse()
        ->and(Gtin::isValid(null))->toBeFalse();
});

it('generates valid in-house EAN-13 codes in the restricted range', function (): void {
    $first = Gtin::inHouseEan13(1);
    $second = Gtin::inHouseEan13(2);

    expect($first)->toHaveLength(13)->and($first)->toStartWith('2')
        ->and(Gtin::isValid($first))->toBeTrue()
        ->and(Gtin::isValid($second))->toBeTrue()
        ->and($first)->not->toBe($second);
});

it('derives a valid GTIN-14 for a logistic unit', function (): void {
    $gtin14 = Gtin::toGtin14('4006381333931', 1);

    expect($gtin14)->toHaveLength(14)->and($gtin14)->toStartWith('1400638133393')->and(Gtin::isValid($gtin14))->toBeTrue();
});

it('builds deterministic SKUs and avoids collisions with a numeric suffix', function (): void {
    expect(SkuBuilder::build('tsh-ess', ['m', 'blk']))->toBe('TSH-ESS-M-BLK')
        ->and(SkuBuilder::build('TSH-ESS', ['M', 'BLK'], ['TSH-ESS-M-BLK' => true]))->toBe('TSH-ESS-M-BLK-2')
        ->and(SkuBuilder::build('TSH-ESS', ['M', 'BLK'], ['TSH-ESS-M-BLK' => true, 'TSH-ESS-M-BLK-2' => true]))->toBe('TSH-ESS-M-BLK-3')
        ->and(SkuBuilder::build('A/B.1', ['é x']))->toBe('A/B.1-EX')
        ->and(strlen(SkuBuilder::build(str_repeat('A', 60), ['X'])))->toBeLessThanOrEqual(SkuBuilder::MAX_LENGTH);
});

it('proposes tokens from labels', function (): void {
    expect(SkuBuilder::tokenOf('Navy blue'))->toBe('NAV')
        ->and(SkuBuilder::tokenOf('XL', 5))->toBe('XL');
});

it('applies fixed and percentage surcharges to the parent figure', function (): void {
    expect(VariantPricing::resolve('19.90', []))->toBe('19.9000')
        ->and(VariantPricing::resolve('19.90', [['amount' => '2', 'type' => 'fixed']]))->toBe('21.9000')
        ->and(VariantPricing::resolve('100', [['amount' => '10', 'type' => 'percent']]))->toBe('110.0000')
        ->and(VariantPricing::resolve('100', [['amount' => '10', 'type' => 'percent'], ['amount' => '5', 'type' => 'percent'], ['amount' => '2.50', 'type' => 'fixed']]))->toBe('117.5000')
        ->and(VariantPricing::resolve('10', [['amount' => '-25', 'type' => 'fixed']]))->toBe('0.0000')
        ->and(VariantPricing::resolve('10', [['amount' => null, 'type' => null]]))->toBe('10.0000');
});

it('converts exactly through the base unit in both directions', function (): void {
    $converter = new UomConverter(['PACK6' => '6', 'CARTON24' => '24', 'PALLET960' => '960'], 4, RoundingMode::HalfUp);

    expect(Dec::trim($converter->toBase('2', 'CARTON24')))->toBe('48')
        ->and(Dec::trim($converter->fromBase('48', 'CARTON24')))->toBe('2')
        ->and(Dec::trim($converter->convert('3', 'PACK6', 'CARTON24')))->toBe('0.75')
        ->and(Dec::trim($converter->convert('1', 'PALLET960', 'PACK6')))->toBe('160')
        ->and(Dec::trim($converter->convert('0.75', 'CARTON24', 'PACK6')))->toBe('3');
});

it('does not drift when a quantity goes there and back many times', function (): void {
    $converter = new UomConverter(['CARTON24' => '24'], 6, RoundingMode::HalfUp);
    $quantity = '1000';

    for ($i = 0; $i < 50; $i++) {
        $quantity = $converter->fromBase($converter->toBase($quantity, 'CARTON24'), 'CARTON24');
    }

    expect(Dec::trim($quantity))->toBe('1000');
});

it('rounds once, following the policy of the product', function (): void {
    $half = new UomConverter(['CARTON24' => '24'], 2, RoundingMode::HalfUp);
    $up = new UomConverter(['CARTON24' => '24'], 2, RoundingMode::Up);
    $down = new UomConverter(['CARTON24' => '24'], 2, RoundingMode::Down);

    expect($half->fromBase('1', 'CARTON24'))->toBe('0.04')
        ->and($up->fromBase('1', 'CARTON24'))->toBe('0.05')
        ->and($down->fromBase('1', 'CARTON24'))->toBe('0.04')
        ->and($half->fromBase('13', 'CARTON24'))->toBe('0.54');
});

it('refuses an inexact conversion when the policy is none', function (): void {
    $strict = new UomConverter(['CARTON24' => '24'], 2, RoundingMode::None);

    expect($strict->fromBase('12', 'CARTON24'))->toBe('0.50')
        ->and(fn () => $strict->fromBase('1', 'CARTON24'))->toThrow(CatalogException::class);
});

it('refuses an unknown unit', function (): void {
    $converter = new UomConverter(['PACK6' => '6']);

    expect(fn () => $converter->toBase('1', 'NOPE'))->toThrow(CatalogException::class)
        ->and(Dec::trim($converter->toBase('5', UomConverter::BASE)))->toBe('5')
        ->and($converter->units())->toBe(['BASE', 'PACK6']);
});

it('splits a base quantity into whole packages, largest first, and adds back exactly', function (): void {
    $converter = new UomConverter(['PACK6' => '6', 'CARTON24' => '24', 'PALLET960' => '960']);
    $split = $converter->split('1030');

    expect($split['parts'])->toBe([
        ['unit' => 'PALLET960', 'quantity' => '1'],
        ['unit' => 'CARTON24', 'quantity' => '2'],
        ['unit' => 'PACK6', 'quantity' => '3'],
    ])->and($split['remainder'])->toBe('4');

    $total = Dec::add(Dec::add(Dec::mul('1', '960'), Dec::mul('2', '24')), Dec::add(Dec::mul('3', '6'), '4'));
    expect(Dec::trim($total))->toBe('1030');
});

it('knows whether a quantity is a whole number of base units', function (): void {
    $converter = new UomConverter(['CARTON24' => '24', 'HALF' => '0.5']);

    expect($converter->isWholeInBase('2', 'CARTON24'))->toBeTrue()
        ->and($converter->isWholeInBase('3', 'HALF'))->toBeFalse()
        ->and($converter->isWholeInBase('4', 'HALF'))->toBeTrue();
});

it('describes what each product type does with stock and kits', function (): void {
    expect(ProductType::Storable->tracksStock())->toBeTrue()
        ->and(ProductType::Consumable->tracksStock())->toBeTrue()
        ->and(ProductType::Service->tracksStock())->toBeFalse()
        ->and(ProductType::Digital->tracksStock())->toBeFalse()
        ->and(ProductType::Digital->canBeKitComponent())->toBeFalse()
        ->and(ProductType::Service->canBeKitComponent())->toBeTrue()
        ->and(ProductType::values())->toBe(['storable', 'consumable', 'service', 'digital'])
        ->and(KitType::values())->toBe(['phantom', 'physical']);
});
