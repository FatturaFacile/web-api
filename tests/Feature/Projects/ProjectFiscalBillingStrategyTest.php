<?php

declare(strict_types=1);

use Illuminate\Support\Facades\App;
use Modules\Projects\Models\ProjectBillingContract;
use Modules\Projects\Models\ProjectSalCertificate;
use Modules\Projects\Services\Fiscal\ChProjectFiscalBillingStrategy;
use Modules\Projects\Services\Fiscal\ItProjectFiscalBillingStrategy;
use Modules\Projects\Services\Fiscal\ProjectFiscalBillingStrategyResolver;

/**
 * @param  array<string, mixed>  $attributes
 */
function strategyContract(array $attributes = []): ProjectBillingContract
{
    return (new ProjectBillingContract)->forceFill([
        'retention_guarantee_percent' => '0', 'cig' => null, 'cup' => null, 'contract_reference' => null, 'contract_date' => null,
        'fiscal_jurisdiction' => 'IT', ...$attributes,
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function strategySal(array $attributes = []): ProjectSalCertificate
{
    return (new ProjectSalCertificate)->forceFill([
        'project_id' => 'p-1', 'sal_number' => 2, 'previous_completion_percent' => '30', 'cumulative_completion_percent' => '60',
        'guarantee_retention_withheld' => '1500', 'is_final' => false, ...$attributes,
    ]);
}

test('the resolver picks the strategy of the fiscal jurisdiction and defaults to Italy', function (): void {
    $resolver = app(ProjectFiscalBillingStrategyResolver::class);

    expect($resolver->forContract(strategyContract(['fiscal_jurisdiction' => 'CH'])))->toBeInstanceOf(ChProjectFiscalBillingStrategy::class)
        ->and($resolver->forContract(strategyContract(['fiscal_jurisdiction' => 'IT'])))->toBeInstanceOf(ItProjectFiscalBillingStrategy::class)
        ->and($resolver->forJurisdiction('xx'))->toBeInstanceOf(ItProjectFiscalBillingStrategy::class)
        ->and($resolver->defaultJurisdictionForCurrency('CHF'))->toBe('CH')
        ->and($resolver->defaultJurisdictionForCurrency('EUR'))->toBe('IT');
});

test('Italy applies 0.50 percent to public contracts only and lets the contract override it', function (): void {
    $it = new ItProjectFiscalBillingStrategy;

    expect($it->effectiveRetentionPercent(strategyContract(['cig' => 'Z1234567AB'])))->toBe('0.5')
        ->and(bcadd($it->effectiveRetentionPercent(strategyContract(['cig' => 'Z1234567AB', 'retention_guarantee_percent' => '4'])), '0', 2))->toBe('4.00')
        ->and($it->effectiveRetentionPercent(strategyContract()))->toBe('0')
        ->and($it->statutoryRetentionPercent(strategyContract()))->toBeNull()
        ->and($it->accountingStandard())->toBe('OIC 23');
});

test('Italy emits DatiSAL and DatiContratto with CIG and CUP and the retention wording', function (): void {
    $header = (new ItProjectFiscalBillingStrategy)->invoiceHeader(
        strategyContract(['cig' => 'Z1234567AB', 'cup' => 'J12B21000010001', 'contract_reference' => 'CONTR-1']),
        strategySal(),
        'PRJ-1 Bridge',
    );
    $general = $header['sdi_extra']['FatturaElettronicaBody']['DatiGenerali'];

    expect($general['DatiSAL'])->toBe([['RiferimentoFase' => 2]])
        ->and($general['DatiContratto'][0])->toBe(['IdDocumento' => 'CONTR-1', 'CodiceCUP' => 'J12B21000010001', 'CodiceCIG' => 'Z1234567AB'])
        ->and($header['notes'])->toContain('SAL n. 2')->toContain('D.Lgs. 36/2023')
        ->and($general['DatiGeneraliDocumento']['Causale'])->not->toBeEmpty();
});

test('Italy omits DatiContratto when the contract states no reference, CIG or CUP', function (): void {
    $header = (new ItProjectFiscalBillingStrategy)->invoiceHeader(strategyContract(), strategySal(['guarantee_retention_withheld' => '0']), 'PRJ-1');

    expect($header['sdi_extra']['FatturaElettronicaBody']['DatiGenerali'])->not->toHaveKey('DatiContratto')
        ->and($header['notes'])->not->toContain('Ritenuta');
});

test('Switzerland has no statutory retention, speaks Akontorechnung and Schlussabrechnung and has no FatturaPA blocks', function (): void {
    App::setLocale('de');
    $ch = new ChProjectFiscalBillingStrategy;
    $contract = strategyContract(['fiscal_jurisdiction' => 'CH', 'retention_guarantee_percent' => '10']);

    expect($ch->statutoryRetentionPercent($contract))->toBeNull()
        ->and($ch->effectiveRetentionPercent(strategyContract(['fiscal_jurisdiction' => 'CH'])))->toBe('0')
        ->and(bcadd($ch->effectiveRetentionPercent($contract), '0', 2))->toBe('10.00')
        ->and($ch->accountingStandard())->toBe('Swiss GAAP FER 22')
        ->and($ch->progressLineDescription($contract, strategySal(), 'Bridge'))->toContain('Akontorechnung')
        ->and($ch->progressLineDescription($contract, strategySal(['is_final' => true]), 'Bridge'))->toContain('Schlussabrechnung')
        ->and($ch->invoiceHeader($contract, strategySal(), 'Bridge'))->not->toHaveKey('sdi_extra')
        ->and($ch->invoiceHeader($contract, strategySal(), 'Bridge')['notes'])->toContain('Garantierückbehalt');
});

test('the QR-bill amount is the invoice total less the retention withheld, never below zero', function (): void {
    $ch = new ChProjectFiscalBillingStrategy;

    expect($ch->payableAmount('61000.00', '5000.00'))->toBe('56000.00')
        ->and($ch->payableAmount('100.00', '500.00'))->toBe('0.00');
});

test('an onerous contract provisions the loss of the work still to be done, under OIC 23 and FER 22 alike', function (): void {
    expect((new ItProjectFiscalBillingStrategy)->expectedLossProvision('100000', '120000', '0.4'))->toBe('12000.00')
        ->and((new ChProjectFiscalBillingStrategy)->expectedLossProvision('100000', '120000', '1'))->toBe('0.00')
        ->and((new ItProjectFiscalBillingStrategy)->expectedLossProvision('100000', '90000', '0.4'))->toBe('0.00');
});
