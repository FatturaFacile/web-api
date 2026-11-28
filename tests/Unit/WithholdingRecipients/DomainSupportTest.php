<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\WithholdingRecipients\Domain\Calculation\Brackets;
use Modules\WithholdingRecipients\Domain\Calculation\CalculatorRegistry;
use Modules\WithholdingRecipients\Domain\Calculation\CashPeriodResolver;
use Modules\WithholdingRecipients\Domain\Calculation\CompensationInput;
use Modules\WithholdingRecipients\Domain\Calculation\CompensationResult;
use Modules\WithholdingRecipients\Domain\Calculation\F24LineBuilder;
use Modules\WithholdingRecipients\Domain\Calculation\InpsRate;
use Modules\WithholdingRecipients\Domain\Enums\RecipientRegime;
use Modules\WithholdingRecipients\Domain\ValueObjects\FiscalCode;
use Modules\WithholdingRecipients\Domain\ValueObjects\Money;

describe('Money', function (): void {
    it('keeps amounts in cents and rounds percentages half up', function (): void {
        expect(Money::of('10.005')->cents)->toBe(1001)
            ->and(Money::of('1234.56')->toDecimal())->toBe('1234.56')
            ->and(Money::of(null)->isZero())->toBeTrue()
            ->and(Money::of('100')->percent('11.5')->toDecimal())->toBe('11.50')
            ->and(Money::of('0.01')->percent('50')->toDecimal())->toBe('0.01')
            ->and(Money::of('-5.50')->toDecimal())->toBe('-5.50');
    });

    it('splits an amount without losing a cent', function (): void {
        [$first, $rest] = Money::of('100.01')->split('33.333333');

        expect($first->plus($rest)->toDecimal())->toBe('100.01');
    });

    it('compares and clamps', function (): void {
        expect(Money::of('5')->greaterThan(Money::of('4.99')))->toBeTrue()
            ->and(Money::of('-3')->floorAtZero()->isZero())->toBeTrue()
            ->and(Money::of('3')->min(Money::of('2'))->toDecimal())->toBe('2.00')
            ->and(Money::of('3')->max(Money::of('2'))->toDecimal())->toBe('3.00')
            ->and(Money::of('2')->lessThanOrEqual(Money::of('2')))->toBeTrue();
    });
});

describe('FiscalCode', function (): void {
    it('rejects malformed codes', function (): void {
        expect(FiscalCode::tryFrom(null))->toBeNull()
            ->and(FiscalCode::tryFrom('ABC'))->toBeNull()
            ->and(FiscalCode::tryFrom('RSSMRA96Z01H501X'))->toBeNull(); // Z is not a month letter
    });

    it('decodes the birth date and the female day offset', function (): void {
        $reference = CarbonImmutable::parse('2026-06-15');

        expect(FiscalCode::tryFrom('rssmra96l01h501x')?->birthDate($reference)?->toDateString())->toBe('1996-07-01')
            ->and(FiscalCode::tryFrom('RSSMRA96L41H501X')?->birthDate($reference)?->toDateString())->toBe('1996-07-01') // day 41 = 1 + 40
            ->and(FiscalCode::tryFrom('VRDLGU70A01H501Z')?->birthDate($reference)?->toDateString())->toBe('1970-01-01');
    });

    it('chooses the century that makes the person at least 14 years old', function (): void {
        $code = FiscalCode::tryFrom('RSSMRA10A01H501X');

        expect($code?->birthDate(CarbonImmutable::parse('2026-06-15'))?->toDateString())->toBe('2010-01-01')
            ->and($code?->birthDate(CarbonImmutable::parse('2020-06-15'))?->toDateString())->toBe('1910-01-01');
    });
});

describe('cash principle and F24 deadline', function (): void {
    it('accrues the tax in the month of the payment', function (): void {
        expect((new CashPeriodResolver)->accrualMonth(CarbonImmutable::parse('2026-03-31')))->toBe('2026-03');
    });

    it('assigns a payment made by 12 January to the previous year for a service of that year (cassa allargata)', function (): void {
        $resolver = new CashPeriodResolver;

        expect($resolver->taxYear(CarbonImmutable::parse('2027-01-12'), CarbonImmutable::parse('2026-12-20')))->toBe(2026)
            ->and($resolver->taxYear(CarbonImmutable::parse('2027-01-13'), CarbonImmutable::parse('2026-12-20')))->toBe(2027)
            ->and($resolver->taxYear(CarbonImmutable::parse('2027-01-05'), CarbonImmutable::parse('2027-01-02')))->toBe(2027);
    });

    it('schedules the F24 on the 16th of the following month', function (): void {
        $resolver = new CashPeriodResolver;

        expect($resolver->f24DueDate(CarbonImmutable::parse('2026-06-15'))->toDateString())->toBe('2026-07-16')
            ->and($resolver->f24DueDate(CarbonImmutable::parse('2026-12-20'))->toDateString())->toBe('2027-01-18'); // 16 Jan 2027 is a Saturday
    });

    it('moves the deadline past weekends and Italian holidays', function (): void {
        $resolver = new CashPeriodResolver;

        // 16 August 2026 is a Sunday, Monday 17 is a working day
        expect($resolver->f24DueDate(CarbonImmutable::parse('2026-07-20'))->toDateString())->toBe('2026-08-17')
            // 16 April 2028: Easter Monday is 17 April 2028
            ->and($resolver->f24DueDate(CarbonImmutable::parse('2028-03-10'))->toDateString())->toBe('2028-04-18');
    });
});

describe('bracket tables', function (): void {
    $irpef = [['up_to' => '28000', 'rate' => '23'], ['up_to' => '50000', 'rate' => '33'], ['up_to' => null, 'rate' => '43']];

    it('taxes each slice at the rate of its bracket', function () use ($irpef): void {
        expect(Brackets::marginal(Money::of('20000'), $irpef)->toDecimal())->toBe('4600.00')
            ->and(Brackets::marginal(Money::of('60000'), $irpef)->toDecimal())->toBe('18000.00') // 6,440 + 7,260 + 4,300
            ->and(Brackets::marginal(Money::zero(), $irpef)->isZero())->toBeTrue();
    });

    it('returns the flat rate of the bracket an amount falls in', function (): void {
        $swiss = [['up_to' => '200', 'rate' => '0'], ['up_to' => '1000', 'rate' => '5'], ['up_to' => null, 'rate' => '20']];

        expect(Brackets::rateFor(Money::of('200'), $swiss))->toBe('0')
            ->and(Brackets::rateFor(Money::of('200.01'), $swiss))->toBe('5')
            ->and(Brackets::rateFor(Money::of('99999'), $swiss))->toBe('20');
    });

    it('picks the INPS rate of the latest year not after the payment year', function (): void {
        $rates = ['2024' => '33.00', '2026' => '35.00'];

        expect(InpsRate::forYear($rates, 2025))->toBe('33.00')
            ->and(InpsRate::forYear($rates, 2030))->toBe('35.00')
            ->and(InpsRate::forYear($rates, 2020))->toBe('33.00'); // before the table: the earliest known rate
    });
});

describe('F24 lines', function (): void {
    it('uses 1040 for self-employment and 1038 for commissions, plus C10 for INPS', function (): void {
        $builder = new F24LineBuilder;
        $paymentDate = CarbonImmutable::parse('2026-06-15');

        $occasional = wrCalcForF24(RecipientRegime::OccasionalWorker, '6000', ['priorCompanyIncome' => Money::of('3000')]);
        $lines = $builder->lines(RecipientRegime::OccasionalWorker, $occasional, $paymentDate, 2026);

        expect($lines)->toHaveCount(2)
            ->and($lines[0])->toMatchArray(['tax_code' => '1040', 'reference_month' => '06', 'reference_year' => 2026, 'amount' => '1200.00', 'section' => 'erario'])
            ->and($lines[1])->toMatchArray(['tax_code' => 'C10', 'section' => 'inps']);

        $agent = wrCalcForF24(RecipientRegime::Agent25Bis, '10000');
        expect($builder->lines(RecipientRegime::Agent25Bis, $agent, $paymentDate, 2026)[0]['tax_code'])->toBe('1038');
    });

    it('feeds no F24 for Swiss payees or when nothing is withheld', function (): void {
        $builder = new F24LineBuilder;
        $swiss = wrCalcForF24(RecipientRegime::SwissForeignArtist, '4000');
        $exemptSports = wrCalcForF24(RecipientRegime::AmateurSports, '3000');

        expect($builder->lines(RecipientRegime::SwissForeignArtist, $swiss, CarbonImmutable::parse('2026-06-15'), 2026))->toBe([])
            ->and($builder->lines(RecipientRegime::AmateurSports, $exemptSports, CarbonImmutable::parse('2026-06-15'), 2026))->toBe([]);
    });
});

/**
 * @param  array<string, mixed>  $overrides
 */
function wrCalcForF24(RecipientRegime $regime, string $gross, array $overrides = []): CompensationResult
{
    return CalculatorRegistry::default()->calculate(new CompensationInput(...array_merge([
        'regime' => $regime,
        'gross' => Money::of($gross),
        'paymentDate' => CarbonImmutable::parse('2026-06-15'),
        'taxYear' => 2026,
    ], $overrides)));
}
