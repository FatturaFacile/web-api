<?php

declare(strict_types=1);

use Modules\StatutoryFinancials\Domain\Enums\AccountingStandard;
use Modules\StatutoryFinancials\Domain\Enums\DossierStatus;
use Modules\StatutoryFinancials\Domain\Enums\RoundingMode;
use Modules\StatutoryFinancials\Domain\Strategy\ProfitAllocationInput;
use Modules\StatutoryFinancials\Pdf\PdfADocumentFactory;
use Modules\StatutoryFinancials\Pdf\PdfAVerifier;
use Modules\StatutoryFinancials\Services\ProfitAllocationCalculator;
use Modules\StatutoryFinancials\Support\AmountFormatter;
use Modules\StatutoryFinancials\Support\Money;

test('money works in bcmath strings and rounds half away from zero', function (): void {
    expect(Money::add('0.1', '0.2'))->toBe(Money::from('0.3'))
        ->and(Money::round('2.345', 2))->toBe('2.35')
        ->and(Money::round('-2.345', 2))->toBe('-2.35')
        ->and(Money::round('2.5', 0))->toBe('3')
        ->and(Money::round('-2.5', 0))->toBe('-3')
        ->and(Money::isNegative('-0.01'))->toBeTrue()
        ->and(Money::isZero('0.0000'))->toBeTrue()
        ->and(Money::compare('10', '9.99'))->toBe(1)
        ->and(Money::min('1', '2'))->toBe(Money::from('1'))
        ->and(Money::div('1', '0'))->toBeNull()
        ->and(Money::percent('50', '0'))->toBeNull();
});

test('amount formatter uses Italian and Swiss separators with a minus sign', function (): void {
    expect(AmountFormatter::format('1234567.891', 2, 'IT'))->toBe('1.234.567,89')
        ->and(AmountFormatter::format('-1234.5', 2, 'CH'))->toBe("-1'234.50")
        ->and(AmountFormatter::format('1234.5', 0, 'IT'))->toBe('1.235');
});

test('the legal reserve takes five percent of the profit until twenty percent of the share capital', function (): void {
    $calculator = new ProfitAllocationCalculator;
    $result = $calculator->calculate(new ProfitAllocationInput('10000', '0', '100000', '0'), 'Art. 2430 c.c.');

    expect($result['legal_reserve_allocation'])->toBe(Money::round('500'))
        ->and($result['carried_forward_out'])->toBe(Money::round('9500'))
        ->and($result['legal_reserve_cap_reached'])->toBeFalse();

    $capped = $calculator->calculate(new ProfitAllocationInput('10000', '0', '100000', '19800'), 'Art. 2430 c.c.');

    expect($capped['legal_reserve_allocation'])->toBe(Money::round('200'));

    $reached = $calculator->calculate(new ProfitAllocationInput('10000', '0', '100000', '20000'), 'Art. 2430 c.c.');

    expect($reached['legal_reserve_allocation'])->toBe(Money::round('0'))->and($reached['legal_reserve_cap_reached'])->toBeTrue();
});

test('a loss allocates no legal reserve and is carried forward, an excessive distribution warns', function (): void {
    $calculator = new ProfitAllocationCalculator;
    $loss = $calculator->calculate(new ProfitAllocationInput('-3000', '500', '50000', '0'), 'Art. 2430 c.c.');

    expect($loss['legal_reserve_allocation'])->toBe(Money::round('0'))->and($loss['carried_forward_out'])->toBe(Money::round('-2500'));

    $excess = $calculator->calculate(new ProfitAllocationInput('1000', '0', '50000', '0', '5000'), 'Art. 2430 c.c.');

    expect($excess['warnings'])->toContain('allocation_exceeds_distributable');
});

test('the standards map to their jurisdiction and the rounding modes to their scale', function (): void {
    foreach (AccountingStandard::cases() as $standard) {
        expect($standard->jurisdiction())->toBeIn(['IT', 'CH'])->and($standard->legalReference())->not->toBe('');
    }

    expect(RoundingMode::ExactCents->scale())->toBe(2)->and(RoundingMode::IntegerUnits->scale())->toBe(0);
});

test('only validated dossiers can be locked and only locked ones filed or reopened, by hand', function (): void {
    expect(DossierStatus::Validated->manualTargets())->toBe([DossierStatus::Locked])
        ->and(DossierStatus::Locked->manualTargets())->toBe([DossierStatus::Filed, DossierStatus::Draft])
        ->and(DossierStatus::Filed->manualTargets())->toBe([])
        ->and(DossierStatus::Draft->manualTargets())->toBe([]);
});

test('generated PDF/A documents pass the structural verifier for both profiles', function (string $profile): void {
    $factory = app(PdfADocumentFactory::class);
    $document = $factory->make($profile, 'Bilancio 2028', 'Acme', 'Statutory', 'it');
    $document->heading('Stato patrimoniale')->paragraph('Società — àèìòù €')->row('Totale attivo', ['1.000,00', '900,00'], true);
    $pdf = $document->render();
    $report = (new PdfAVerifier)->verify($pdf, $profile);

    expect($pdf)->toStartWith('%PDF-1.')
        ->and($report['compliant'])->toBeTrue()
        ->and(array_filter($report['checks'], static fn (array $check): bool => ! $check['passed']))->toBe([]);
})->with(['PDF/A-1b', 'PDF/A-2b']);

test('the verifier rejects a pdf without PDF/A metadata', function (): void {
    $report = (new PdfAVerifier)->verify("%PDF-1.4\n1 0 obj\n<< >>\nendobj\n%%EOF\n", 'PDF/A-2b');

    expect($report['compliant'])->toBeFalse();
});
