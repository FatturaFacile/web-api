<?php

declare(strict_types=1);

use Modules\ExpenseManagement\Domain\Enums\AllowanceMethod;
use Modules\ExpenseManagement\Domain\Enums\DestinationType;
use Modules\ExpenseManagement\Domain\Enums\PaymentMethod;
use Modules\ExpenseManagement\Domain\ValueObjects\LineFacts;
use Modules\ExpenseManagement\Domain\ValueObjects\PerDiemFacts;
use Modules\ExpenseManagement\Services\VehicleRateRepository;
use Modules\ExpenseManagement\Strategies\FiscalStrategyRegistry;
use Modules\ExpenseManagement\Strategies\ItalianNoteSpeseStrategy;
use Modules\ExpenseManagement\Strategies\SwissSpesenStrategy;

function exmFacts(string $category, PaymentMethod $method, bool $traceable, string $amount = '50.00', string $vat = '4.55', ?string $taxId = 'IT01234567890', bool $block = false): LineFacts
{
    return new LineFacts($category, $category === 'meals', $category === 'lodging', $category === 'local_transport', true, $method, $traceable, $amount, $vat, $taxId, $block);
}

function exmDays(AllowanceMethod $method, DestinationType $type, string $country, string $days, ?string $rate, bool $lodging = false, bool $meals = false, ?string $policyLimit = null): PerDiemFacts
{
    return new PerDiemFacts($country, $type, $method, $days, $rate, $lodging, $meals, $policyLimit);
}

it('resolves the strategy by jurisdiction with Italy as fallback and lets a country register its own', function (): void {
    $registry = new FiscalStrategyRegistry;

    expect($registry->for('CH'))->toBeInstanceOf(SwissSpesenStrategy::class)
        ->and($registry->for('IT'))->toBeInstanceOf(ItalianNoteSpeseStrategy::class)
        ->and($registry->for('XX'))->toBeInstanceOf(ItalianNoteSpeseStrategy::class)
        ->and(FiscalStrategyRegistry::jurisdictionOfCountry('ch'))->toBe('CH')
        ->and(FiscalStrategyRegistry::jurisdictionOfCountry('DE'))->toBe('IT');

    $registry->extend('DE', fn (): SwissSpesenStrategy => new SwissSpesenStrategy(new VehicleRateRepository));
    expect($registry->for('de'))->toBeInstanceOf(SwissSpesenStrategy::class);
});

it('marks cash meals, lodging and taxi as non-traceable taxable benefits in Italy, blocking when the policy says so', function (string $category): void {
    $strategy = new ItalianNoteSpeseStrategy(new VehicleRateRepository);

    $warning = $strategy->assessLine(exmFacts($category, PaymentMethod::EmployeeOutOfPocket, false));
    expect($warning->isTraceable)->toBeFalse()->and($warning->isTaxableBenefit)->toBeTrue()
        ->and($warning->vatRecoverableAmount)->toBe('0.00')
        ->and($warning->violations[0])->toMatchArray(['code' => 'non_traceable_payment', 'severity' => 'warning']);

    $blocking = $strategy->assessLine(exmFacts($category, PaymentMethod::EmployeeOutOfPocket, false, block: true));
    expect($blocking->violations[0]['severity'])->toBe('blocking');
})->with(['meals', 'lodging', 'local_transport']);

it('treats the corporate card as traceable and recovers the VAT only with a P.IVA (IT)', function (): void {
    $strategy = new ItalianNoteSpeseStrategy(new VehicleRateRepository);

    $card = $strategy->assessLine(exmFacts('meals', PaymentMethod::CorporateCard, false));
    expect($card->isTraceable)->toBeTrue()->and($card->isTaxableBenefit)->toBeFalse()->and($card->vatRecoverableAmount)->toBe('4.55');

    $noTaxId = $strategy->assessLine(exmFacts('meals', PaymentMethod::EmployeeOutOfPocket, true, taxId: null));
    expect($noTaxId->vatRecoverableAmount)->toBe('0.00')->and(collect($noTaxId->violations)->pluck('code')->all())->toContain('missing_merchant_tax_id', 'vat_not_recoverable');
});

it('does not apply the Italian traceability rule in Switzerland and recovers VAT up to CHF 400 without the UID', function (): void {
    $strategy = new SwissSpesenStrategy(new VehicleRateRepository);

    $cash = $strategy->assessLine(exmFacts('meals', PaymentMethod::EmployeeOutOfPocket, false, taxId: null));
    expect($cash->isTaxableBenefit)->toBeFalse()->and($cash->vatRecoverableAmount)->toBe('4.55');

    $large = $strategy->assessLine(exmFacts('lodging', PaymentMethod::EmployeeOutOfPocket, true, amount: '500.00', vat: '38.00', taxId: null));
    expect($large->vatRecoverableAmount)->toBe('0.00');

    $withUid = $strategy->assessLine(exmFacts('lodging', PaymentMethod::EmployeeOutOfPocket, true, amount: '500.00', vat: '38.00', taxId: 'CHE-123.456.789'));
    expect($withUid->vatRecoverableAmount)->toBe('38.00');
});

it('computes the Italian per-diem limits and reductions', function (): void {
    $strategy = new ItalianNoteSpeseStrategy(new VehicleRateRepository);

    $domestic = $strategy->calculatePerDiem(exmDays(AllowanceMethod::FlatRate, DestinationType::Domestic, 'IT', '3.00', null));
    expect($domestic->ratePerDay)->toBe('46.48')->and($domestic->grossAmount)->toBe('139.44')->and($domestic->taxableExcessAmount)->toBe('0.00');

    $abroad = $strategy->calculatePerDiem(exmDays(AllowanceMethod::FlatRate, DestinationType::International, 'DE', '1.00', '100.00'));
    expect($abroad->dailyTaxFreeLimit)->toBe('77.47')->and($abroad->taxableExcessAmount)->toBe('22.53');

    $mixed = $strategy->calculatePerDiem(exmDays(AllowanceMethod::Mixed, DestinationType::Domestic, 'IT', '1.00', null, lodging: true));
    expect($mixed->dailyTaxFreeLimit)->toBe('30.99')->and($mixed->ratePerDay)->toBe('30.99')->and($mixed->reductions)->toBe('15.49')->and($mixed->taxableExcessAmount)->toBe('0.00');

    $analytic = $strategy->calculatePerDiem(exmDays(AllowanceMethod::Analytic, DestinationType::Domestic, 'IT', '2.00', '20.00'));
    expect($analytic->dailyTaxFreeLimit)->toBe('15.49')->and($analytic->taxFreeAmount)->toBe('30.98')->and($analytic->taxableExcessAmount)->toBe('9.02');

    $municipality = $strategy->calculatePerDiem(exmDays(AllowanceMethod::FlatRate, DestinationType::Municipality, 'IT', '1.00', '30.00'));
    expect($municipality->taxFreeAmount)->toBe('0.00')->and($municipality->taxableExcessAmount)->toBe('30.00')->and($municipality->warnings[0]['code'])->toBe('taxable_per_diem_excess');
});

it('computes the Swiss per-diem against the regulation limit', function (): void {
    $strategy = new SwissSpesenStrategy(new VehicleRateRepository);

    $domestic = $strategy->calculatePerDiem(exmDays(AllowanceMethod::FlatRate, DestinationType::Domestic, 'CH', '1.00', '130.00'));
    expect($domestic->dailyTaxFreeLimit)->toBe('120.00')->and($domestic->taxableExcessAmount)->toBe('10.00');

    $custom = $strategy->calculatePerDiem(exmDays(AllowanceMethod::FlatRate, DestinationType::Domestic, 'CH', '1.00', '130.00', policyLimit: '140.00'));
    expect($custom->taxableExcessAmount)->toBe('0.00');

    $international = $strategy->calculatePerDiem(exmDays(AllowanceMethod::FlatRate, DestinationType::International, 'FR', '1.00', null));
    expect($international->ratePerDay)->toBe('180.00');
});

it('lists the fiscal notices of each jurisdiction', function (): void {
    expect((new ItalianNoteSpeseStrategy(new VehicleRateRepository))->noticeCodes())->toContain('it_art51_tuir', 'it_traceable_payment')
        ->and((new SwissSpesenStrategy(new VehicleRateRepository))->noticeCodes())->toContain('ch_spesenreglement');
});
