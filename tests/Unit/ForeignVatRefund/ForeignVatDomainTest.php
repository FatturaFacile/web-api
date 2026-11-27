<?php

declare(strict_types=1);

use Database\Seeders\Translations\ForeignVatRefundFrontendTranslations;
use Modules\ForeignVatRefund\Domain\Enums\ClaimStatus;
use Modules\ForeignVatRefund\Domain\Support\ExpenseCategoryGuesser;
use Modules\ForeignVatRefund\Domain\Support\Jurisdictions;
use Modules\ForeignVatRefund\Services\ForeignVatCalculator;

it('splits the VAT into the recoverable and the non-recoverable part with cent rounding', function (string $vat, string $percentage, string $recoverable, string $nonRecoverable): void {
    expect((new ForeignVatCalculator)->split($vat, $percentage))->toBe(['recoverable' => $recoverable, 'non_recoverable' => $nonRecoverable]);
})->with([
    ['190.00', '100', '190.00', '0.00'],
    ['200.00', '80', '160.00', '40.00'],
    ['190.00', '50', '95.00', '95.00'],
    ['0.01', '50', '0.01', '0.00'],
    ['100.00', '0', '0.00', '100.00'],
    ['100.00', '150', '100.00', '0.00'],
]);

it('converts and computes VAT with two decimals', function (): void {
    $calculator = new ForeignVatCalculator;

    expect($calculator->toBase('760.00', '1.05'))->toBe('798.00')
        ->and($calculator->vatOf('1000.00', '19'))->toBe('190.00')
        ->and($calculator->vatOf('20000', '3.8'))->toBe('760.00');
});

it('reads the country of a VAT number and the EU membership', function (): void {
    expect(Jurisdictions::countryOfVatNumber('CHE-123.456.788 MWST'))->toBe('CH')
        ->and(Jurisdictions::countryOfVatNumber('DE123456789'))->toBe('DE')
        ->and(Jurisdictions::countryOfVatNumber('EL123456789'))->toBe('GR')
        ->and(Jurisdictions::countryOfVatNumber('12345'))->toBeNull()
        ->and(Jurisdictions::countryOfVatNumber(null))->toBeNull()
        ->and(Jurisdictions::isEuMemberState('fr'))->toBeTrue()
        ->and(Jurisdictions::isEuMemberState('CH'))->toBeFalse()
        ->and(Jurisdictions::currencyOf('ch'))->toBe('CHF')
        ->and(Jurisdictions::quarterOf('2026-05-10'))->toBe(2)
        ->and(Jurisdictions::quarterOf('2026-12-31'))->toBe(4);
});

it('guesses the expense category from the description in any language', function (string $description, int $code): void {
    expect(ExpenseCategoryGuesser::guess($description))->toBe($code);
})->with([
    ['Hotel Berlin 2 nights', 6],
    ['Pedaggio autostrada', 4],
    ['Messe Frankfurt stand', 8],
    ['Ristorante cena clienti', 7],
]);

it('allows only the lifecycle transitions of the claim', function (): void {
    expect(ClaimStatus::Draft->canTransitionTo(ClaimStatus::ReadyToSubmit))->toBeTrue()
        ->and(ClaimStatus::Draft->canTransitionTo(ClaimStatus::Submitted))->toBeFalse()
        ->and(ClaimStatus::Submitted->canTransitionTo(ClaimStatus::PartiallyApproved))->toBeTrue()
        ->and(ClaimStatus::PartiallyApproved->next())->toBe([ClaimStatus::Refunded])
        ->and(ClaimStatus::Refunded->next())->toBe([])
        ->and(ClaimStatus::Rejected->isFinal())->toBeTrue()
        ->and(ClaimStatus::Draft->locksItems())->toBeFalse()
        ->and(ClaimStatus::Submitted->locksItems())->toBeTrue();
});

it('has every translation in the four locales and the same placeholders', function (): void {
    $definitions = ForeignVatRefundFrontendTranslations::definitions();

    expect($definitions)->not->toBeEmpty();

    foreach ($definitions as $key => $tuple) {
        expect($tuple)->toHaveCount(4, $key);

        $placeholders = array_map(static function (string $text): array {
            preg_match_all('/\{(\w+)\}/', $text, $matches);
            sort($matches[1]);

            return $matches[1];
        }, $tuple);

        foreach ($tuple as $index => $text) {
            expect(trim($text))->not->toBe('', "{$key}[{$index}]")->and($placeholders[$index])->toBe($placeholders[1], $key);
        }
    }
});

it('keeps the four backend language files aligned', function (): void {
    $keys = static function (string $locale): array {
        $keys = array_keys(array_dot(require base_path("lang/{$locale}/foreign_vat_refund.php")));
        sort($keys);

        return $keys;
    };

    expect($keys('it'))->toBe($keys('en'))->and($keys('de'))->toBe($keys('en'))->and($keys('fr'))->toBe($keys('en'));
});
