<?php

declare(strict_types=1);

use Modules\SwissVat\Domain\Enums\InputTaxAdjustmentType;
use Modules\SwissVat\Services\InputTaxAdjustmentService;
use Modules\SwissVat\Services\SwissVatCalculatorService;
use Modules\SwissVat\Services\SwissVatRateCatalogService;

test('effective method computes 2024+ rates (8.1%, 2.6%, 3.8%), exempt with/without deduction, Bezugsteuer, and LIVA adjustments', function (): void {
    $catalog = new SwissVatRateCatalogService;
    $calculator = new SwissVatCalculatorService($catalog);

    $result = $calculator->calculate([
        'period_from' => '2026-01-01',
        'period_to' => '2026-03-31',
        'accounting_method' => 'effective',
        'rounding_mode' => 'chf_005',
        'sales_lines' => [
            ['net_amount' => '10000.00', 'tax_rate' => '8.10', 'exemption_type' => 'none', 'afc_box' => '303'],
            ['net_amount' => '2000.00', 'tax_rate' => '2.60', 'exemption_type' => 'none', 'afc_box' => '313'],
            ['net_amount' => '1000.00', 'tax_rate' => '3.80', 'exemption_type' => 'none', 'afc_box' => '343'],
            ['net_amount' => '5000.00', 'tax_rate' => '0.00', 'exemption_type' => 'exempt_with_deduction', 'afc_box' => '220'],
            ['net_amount' => '1500.00', 'tax_rate' => '0.00', 'exemption_type' => 'exempt_without_deduction', 'afc_box' => '230'],
            ['net_amount' => '800.00', 'tax_rate' => '0.00', 'exemption_type' => 'out_of_scope', 'afc_box' => '900'],
        ],
        'purchase_lines' => [
            ['net_amount' => '3000.00', 'input_vat_amount' => '243.00', 'afc_box' => '400', 'is_deductible' => true],
            ['net_amount' => '1000.00', 'input_vat_amount' => '81.00', 'afc_box' => '405', 'is_deductible' => true],
        ],
        'bezugsteuer_entries' => [
            ['net_amount_chf' => '1000.00', 'tax_amount_chf' => '81.00', 'vat_rate_percent' => '8.10', 'deductible_input_tax_chf' => '81.00'],
        ],
        'adjustments' => [
            'box_410' => '120.00', // Degrassazione
            'box_415' => '45.00',  // Autoconsumo
            'box_420' => '15.00',  // Subsidy reduction
        ],
    ]);

    // Total turnover 200 = 10000 + 2000 + 1000 + 5000 + 1500 = 19500.00 (900 out-of-scope excluded from 200)
    // Deductions 289 = 5000 (220) + 1500 (230) = 6500.00; Taxable 299 = 13000.00
    // Output VAT = 810 + 52 + 38 + 81 (Bezugsteuer) = 981.00
    // Input VAT 479 = (243 + 81 [Bezugsteuer]) + 81 (405) + 120 (410) - 45 (415) - 15 (420) = 465.00
    // Net payable 500 = 981.00 - 465.00 = 516.00
    expect($result['box_200'])->toBe('19500.00')
        ->and($result['box_289'])->toBe('6500.00')
        ->and($result['box_299'])->toBe('13000.00')
        ->and($result['afc_boxes']['220'])->toBe('5000.00')
        ->and($result['afc_boxes']['230'])->toBe('1500.00')
        ->and($result['afc_boxes']['900'])->toBe('800.00')
        ->and($result['afc_boxes']['303']['tax'])->toBe('810.00')
        ->and($result['afc_boxes']['313']['tax'])->toBe('52.00')
        ->and($result['afc_boxes']['343']['tax'])->toBe('38.00')
        ->and($result['afc_boxes']['383']['tax'])->toBe('81.00')
        ->and($result['box_399'])->toBe('981.00')
        ->and($result['box_479'])->toBe('465.00')
        ->and($result['box_500'])->toBe('516.00')
        ->and($result['box_510'])->toBe('0.00');
});

test('effective method maps historical 2018-2023 periods to boxes 302, 312, 342, 382', function (): void {
    $calculator = new SwissVatCalculatorService(new SwissVatRateCatalogService);

    $result = $calculator->calculate([
        'period_from' => '2023-10-01',
        'period_to' => '2023-12-31',
        'accounting_method' => 'effective',
        'sales_lines' => [
            ['net_amount' => '1000.00', 'tax_rate' => '7.70'],
            ['net_amount' => '1000.00', 'tax_rate' => '2.50'],
            ['net_amount' => '1000.00', 'tax_rate' => '3.70'],
        ],
    ]);

    expect($result['afc_boxes']['epoch'])->toBe('2018-2023')
        ->and($result['afc_boxes']['302']['tax'])->toBe('77.00')
        ->and($result['afc_boxes']['312']['tax'])->toBe('25.00')
        ->and($result['afc_boxes']['342']['tax'])->toBe('37.00')
        ->and($result['box_399'])->toBe('139.00');
});

test('saldosteuersatz method taxes gross turnover, forfeits input VAT, and adds Bezugsteuer', function (): void {
    $calculator = new SwissVatCalculatorService(new SwissVatRateCatalogService);

    $result = $calculator->calculate([
        'period_from' => '2026-01-01',
        'period_to' => '2026-06-30',
        'accounting_method' => 'saldosteuersatz',
        'rounding_mode' => 'chf_005',
        'sss_rate_primary' => '6.20',
        'sss_rate_secondary' => '2.10',
        'sales_lines' => [
            ['net_amount' => '10000.00', 'tax_rate' => '8.10', 'sss_rate' => '6.20'], // Gross 10,810 * 6.2% = 670.22
            ['net_amount' => '1000.00', 'tax_rate' => '8.10', 'sss_rate' => '2.10'],  // Gross 1,081 * 2.1% = 22.70
        ],
        'purchase_lines' => [
            ['net_amount' => '2000.00', 'input_vat_amount' => '162.00', 'afc_box' => '400', 'is_deductible' => true],
        ],
        'bezugsteuer_entries' => [
            ['net_amount_chf' => '1000.00', 'tax_amount_chf' => '81.00', 'vat_rate_percent' => '8.10', 'deductible_input_tax_chf' => '0.00'],
        ],
    ]);

    expect($result['gross_turnover'])->toBe('11891.00')
        ->and($result['box_200'])->toBe('11891.00')
        ->and($result['box_299'])->toBe('11891.00')
        ->and($result['box_399'])->toBe('773.92')
        ->and($result['box_479'])->toBe('0.00')
        ->and($result['forfeited_input_vat'])->toBe('162.00')
        ->and($result['invoiced_vat_total'])->toBe('891.00')
        ->and($result['sss_revenue_adjustment'])->toBe('198.08')
        ->and($result['box_500'])->toBe('773.90'); // 0.05 CHF rounded
});

test('input tax adjustment depreciation computes residual autoconsumo and degrassazione per LIVA Art. 31-32', function (): void {
    $service = new InputTaxAdjustmentService;

    // Movable asset: 10,000 CHF @ 8.1% = 810 CHF, 2 years elapsed out of 5 => 3/5 = 486.00 CHF
    $autoconsumo = $service->computeAdjustmentAmount(
        type: InputTaxAdjustmentType::Autoconsumo,
        directAmount: null,
        originalCostChf: '10000.00',
        originalVatRate: '8.10',
        yearsElapsed: 2,
        assetCategory: 'movable',
        privateOrExemptRatio: '100',
    );

    // Real estate: 200,000 CHF @ 8.1% = 16,200 CHF, 5 years elapsed out of 20 => 15/20 = 12,150.00 CHF
    $degrassazione = $service->computeAdjustmentAmount(
        type: InputTaxAdjustmentType::Degrassazione,
        directAmount: null,
        originalCostChf: '200000.00',
        originalVatRate: '8.10',
        yearsElapsed: 5,
        assetCategory: 'real_estate',
        privateOrExemptRatio: '100',
    );

    expect($autoconsumo)->toBe('486.00')
        ->and($degrassazione)->toBe('12150.00');
});
