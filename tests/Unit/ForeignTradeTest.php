<?php

declare(strict_types=1);

use App\Enums\Incoterm;
use App\Services\ForeignTrade\CrossBorderVatRegimeResolver;
use App\Services\ForeignTrade\CustomsValueCalculator;

it('lists the eleven Incoterms 2020', function (): void {
    expect(Incoterm::values())->toHaveCount(11)->toContain('DAP', 'DPU', 'DDP');
});

it('adds freight and insurance to the customs value only when the Incoterm leaves them out of the price', function (string $incoterm, float $expected): void {
    $value = (new CustomsValueCalculator)->calculate(Incoterm::from($incoterm), 1000.0, 100.0, 10.0);

    expect($value->customsValue)->toBe($expected)->and($value->statisticalValue)->toBe($expected);
})->with([
    ['EXW', 1110.0],
    ['FCA', 1110.0],
    ['FOB', 1110.0],
    ['CPT', 1010.0],
    ['CIF', 1000.0],
    ['CIP', 1000.0],
    ['DAP', 1000.0],
    ['DDP', 1000.0],
]);

it('resolves the VAT regime of cross-border supplies', function (string $tenant, string $other, string $regime, bool $exempt, bool $vies, bool $customs): void {
    $result = (new CrossBorderVatRegimeResolver)->resolve($tenant, $other);

    expect($result['regime'])->toBe($regime)
        ->and($result['vat_exempt'])->toBe($exempt)
        ->and($result['requires_vies'])->toBe($vies)
        ->and($result['requires_customs_declaration'])->toBe($customs);
})->with([
    ['IT', 'IT', 'domestic', false, false, false],
    ['CH', 'IT', 'ch_export_art_23_ltva', true, false, true],
    ['IT', 'CH', 'it_export_art_8_dpr_633_72', true, false, true],
    ['IT', 'DE', 'it_intra_eu_art_41_dl_331_93', true, true, false],
    ['IT', 'US', 'it_export_art_8_dpr_633_72', true, false, true],
]);

it('applies reverse charge to intra-EU purchases and import tax to Swiss imports', function (): void {
    $resolver = new CrossBorderVatRegimeResolver;

    expect($resolver->resolve('IT', 'FR', true, true)['reverse_charge'])->toBeTrue()
        ->and($resolver->resolve('CH', 'IT', true, true)['regime'])->toBe('ch_import_tax_zaz')
        ->and($resolver->resolve('IT', 'FR', false)['regime'])->toBe('domestic');
});
