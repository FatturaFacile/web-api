<?php

declare(strict_types=1);

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Modules\SwissVat\Models\SwissVatConfig;
use Modules\SwissVat\Models\SwissVatReturn;
use Modules\SwissVat\Services\BezugsteuerDetectionService;
use Modules\SwissVat\Services\InputTaxAdjustmentService;
use Modules\SwissVat\Services\SuisseTaxXmlBuilder;
use Modules\SwissVat\Services\SuisseTaxXsdValidator;
use Modules\SwissVat\Services\SwissVatCalculatorService;
use Modules\SwissVat\Services\SwissVatRateCatalogService;
use Modules\SwissVat\Services\SwissVatReconciliationService;

function makeSampleReturnAndConfig(string $uid = 'CHE-116.281.710 MWST'): array
{
    $catalog = new SwissVatRateCatalogService;
    $calculator = new SwissVatCalculatorService($catalog);
    $computed = $calculator->calculate([
        'period_from' => '2026-01-01',
        'period_to' => '2026-03-31',
        'accounting_method' => 'effective',
        'sales_lines' => [
            ['net_amount' => '10000.00', 'tax_rate' => '8.10'],
        ],
        'purchase_lines' => [
            ['net_amount' => '2000.00', 'input_vat_amount' => '162.00'],
        ],
    ]);

    $vatReturn = new SwissVatReturn([
        'fiscal_year' => 2026,
        'period_type' => 'quarterly',
        'period_number' => 1,
        'period_label' => '2026-Q1',
        'period_from' => '2026-01-01',
        'period_to' => '2026-03-31',
        'accounting_method' => 'effective',
        'consideration_basis' => 'agreed',
        'currency' => 'CHF',
        'box_399_total_output_tax' => $computed['box_399'],
        'box_500_payable_amount' => $computed['box_500'],
        'box_510_credit_amount' => $computed['box_510'],
        'afc_boxes' => $computed['afc_boxes'],
        'quadratura_passed' => true,
    ]);

    $tenant = new Tenant([
        'name' => 'Helvetia AG',
        'legal_name' => 'Helvetia Treuhand AG',
        'uid_number' => $uid,
    ]);

    $config = new SwissVatConfig([
        'uid_mwst' => $uid,
        'accounting_method' => 'effective',
    ]);

    return [$vatReturn, $tenant, $config, $catalog, $calculator];
}

test('SuisseTax eCH-0217 XML passes XSD schema validation and quadratura', function (): void {
    [$vatReturn, $tenant, $config, $catalog, $calculator] = makeSampleReturnAndConfig();

    $reconciliation = new SwissVatReconciliationService(
        $calculator,
        new BezugsteuerDetectionService($catalog),
        new InputTaxAdjustmentService,
        $catalog,
    );
    $quadratura = $reconciliation->evaluateQuadratura(
        $vatReturn->afc_boxes,
        'effective',
        $config->uid_mwst,
        0,
        CarbonImmutable::parse('2026-03-31'),
    );

    $builder = new SuisseTaxXmlBuilder($catalog);
    $xml = $builder->build($vatReturn, $tenant, $config);
    $validator = new SuisseTaxXsdValidator;
    $result = $validator->validate($xml, $vatReturn, $config, $quadratura);

    expect($result->valid)->toBeTrue()
        ->and($result->xsdErrors)->toBe([])
        ->and($result->businessErrors)->toBe([])
        ->and($xml)->toContain('http://www.ech.ch/xmlns/eCH-0217/2')
        ->and($xml)->toContain('CHE-116.281.710 MWST');
});

test('SuisseTaxXsdValidator catches malformed XML, invalid UID-MWST, and quadratura blockers', function (): void {
    [$vatReturn, $tenant, $config, $catalog, $calculator] = makeSampleReturnAndConfig('CHE-000.000.000 MWST');

    $brokenBoxes = $vatReturn->afc_boxes;
    $brokenBoxes['299'] = '999.00'; // Breaks Q1 & Q3 quadratura

    $reconciliation = new SwissVatReconciliationService(
        $calculator,
        new BezugsteuerDetectionService($catalog),
        new InputTaxAdjustmentService,
        $catalog,
    );
    $quadratura = $reconciliation->evaluateQuadratura(
        $brokenBoxes,
        'effective',
        'CHE-000.000.000 MWST',
        1,
        CarbonImmutable::parse('2026-03-31'),
    );

    $validator = new SuisseTaxXsdValidator;
    $malformedErrors = $validator->xsdErrors('<vatDeclaration><unclosed></vatDeclaration>');
    $businessResult = $validator->validate(
        (new SuisseTaxXmlBuilder($catalog))->build($vatReturn, $tenant, $config),
        $vatReturn,
        $config,
        $quadratura,
    );

    expect($malformedErrors)->not->toBeEmpty()
        ->and($quadratura->passed)->toBeFalse()
        ->and($quadratura->blockers())->not->toBeEmpty()
        ->and($businessResult->valid)->toBeFalse()
        ->and($businessResult->businessErrors)->not->toBeEmpty();
});
