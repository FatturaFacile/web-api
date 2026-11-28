<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\WithholdingRecipients\Domain\Calculation\CalculatorRegistry;
use Modules\WithholdingRecipients\Domain\Calculation\CompensationInput;
use Modules\WithholdingRecipients\Domain\Calculation\CompensationResult;
use Modules\WithholdingRecipients\Domain\Enums\ActivityType;
use Modules\WithholdingRecipients\Domain\Enums\EnasarcoMandate;
use Modules\WithholdingRecipients\Domain\Enums\RecipientRegime;
use Modules\WithholdingRecipients\Domain\ValueObjects\Money;

/**
 * @param  array<string, mixed>  $overrides
 */
function wrCalc(RecipientRegime $regime, string $gross, array $overrides = []): CompensationResult
{
    $input = new CompensationInput(...array_merge([
        'regime' => $regime,
        'gross' => Money::of($gross),
        'paymentDate' => CarbonImmutable::parse('2026-06-15'),
        'taxYear' => 2026,
    ], $overrides));

    return CalculatorRegistry::default()->calculate($input);
}

describe('agents (Art. 25-bis)', function (): void {
    it('withholds 23% on 50% of the commission: 11.50% effective', function (): void {
        $result = wrCalc(RecipientRegime::Agent25Bis, '10000', ['enasarcoMandate' => EnasarcoMandate::Mono]);

        expect($result->taxableBase->toDecimal())->toBe('5000.00')
            ->and($result->withholding->toDecimal())->toBe('1150.00')
            ->and($result->effectiveRate())->toBe('11.50')
            ->and($result->cuCausal)->toBe('Q')
            ->and($result->notes)->toContain('agent_standard_base_50');
    });

    it('withholds 23% on 20% of the commission with the continuous-use declaration: 4.60% effective', function (): void {
        $result = wrCalc(RecipientRegime::Agent25Bis, '10000', ['hasReducedWithholdingCertificate' => true, 'enasarcoMandate' => EnasarcoMandate::Pluri]);

        expect($result->taxableBase->toDecimal())->toBe('2000.00')
            ->and($result->withholding->toDecimal())->toBe('460.00')
            ->and($result->effectiveRate())->toBe('4.60')
            ->and($result->cuCausal)->toBe('R')
            ->and($result->notes)->toContain('agent_reduced_base_20');
    });

    it('splits Enasarco between agent and company and caps it at the yearly ceiling', function (): void {
        $first = wrCalc(RecipientRegime::Agent25Bis, '10000', ['enasarcoMandate' => EnasarcoMandate::Mono]);
        expect($first->enasarcoAgent->toDecimal())->toBe('875.00')
            ->and($first->enasarcoCompany->toDecimal())->toBe('875.00');

        $capped = wrCalc(RecipientRegime::Agent25Bis, '10000', ['enasarcoMandate' => EnasarcoMandate::Mono, 'priorCompanyIncome' => Money::of('40000')]);
        expect($capped->enasarcoBase->toDecimal())->toBe('6000.00')
            ->and($capped->enasarcoAgent->toDecimal())->toBe('525.00')
            ->and($capped->warnings)->toContain('enasarco_ceiling_reached');

        $beyond = wrCalc(RecipientRegime::Agent25Bis, '5000', ['enasarcoMandate' => EnasarcoMandate::Pluri, 'priorCompanyIncome' => Money::of('30000')]);
        expect($beyond->enasarcoBase->isZero())->toBeTrue();
    });

    it('accrues FIRR on progressive brackets of the yearly commissions at the company cost', function (): void {
        $result = wrCalc(RecipientRegime::Agent25Bis, '10000', ['enasarcoMandate' => EnasarcoMandate::Mono, 'firrEnabled' => true, 'priorCompanyIncome' => Money::of('5000')]);

        // 5,000 -> 15,000: 7,500 at 4% (300) + 2,500 at 2% (50)
        expect($result->firrAccrual->toDecimal())->toBe('350.00')
            ->and($result->companyCost->toDecimal())->toBe('11225.00'); // 10,000 + 875 Enasarco + 350 FIRR
    });

    it('nets the Enasarco agent share and the withholding from the payout', function (): void {
        $result = wrCalc(RecipientRegime::Agent25Bis, '10000', ['enasarcoMandate' => EnasarcoMandate::Mono]);

        expect($result->netPayable->toDecimal())->toBe('7975.00'); // 10,000 - 1,150 - 875
    });

    it('warns when the Enasarco mandate is unknown', function (): void {
        expect(wrCalc(RecipientRegime::Agent25Bis, '1000')->warnings)->toContain('enasarco_mandate_missing');
    });
});

describe('copyright and royalties (Art. 53 c. 2 lett. b TUIR)', function (): void {
    it('uses the 40% flat deduction under 35 years: 12.00% effective', function (): void {
        $result = wrCalc(RecipientRegime::AuthorRoyalty, '1000', ['birthDate' => CarbonImmutable::parse('1996-07-01')]);

        expect($result->flatRateDeduction->toDecimal())->toBe('400.00')
            ->and($result->taxableBase->toDecimal())->toBe('600.00')
            ->and($result->withholding->toDecimal())->toBe('120.00')
            ->and($result->effectiveRate())->toBe('12.00')
            ->and($result->nonTaxableCode)->toBe('22')
            ->and($result->cuCausal)->toBe('B')
            ->and($result->notes)->toContain('royalty_under_35', 'out_of_vat_scope_art_3_c4_a');
    });

    it('uses the 25% flat deduction from 35 years on: 15.00% effective', function (): void {
        $result = wrCalc(RecipientRegime::AuthorRoyalty, '1000', ['birthDate' => CarbonImmutable::parse('1980-01-01')]);

        expect($result->flatRateDeduction->toDecimal())->toBe('250.00')
            ->and($result->withholding->toDecimal())->toBe('150.00')
            ->and($result->effectiveRate())->toBe('15.00')
            ->and($result->netPayable->toDecimal())->toBe('850.00');
    });

    it('turns 35 exactly on the birthday of the payment date', function (): void {
        $day = wrCalc(RecipientRegime::AuthorRoyalty, '1000', ['birthDate' => CarbonImmutable::parse('1991-06-15')]);
        $before = wrCalc(RecipientRegime::AuthorRoyalty, '1000', ['birthDate' => CarbonImmutable::parse('1991-06-16')]);

        expect($day->flatRateDeduction->toDecimal())->toBe('250.00')
            ->and($before->flatRateDeduction->toDecimal())->toBe('400.00');
    });

    it('reads the age from the Codice Fiscale when no birth date is recorded', function (): void {
        // RSSMRA96L01H501X: born 1 July 1996
        $young = wrCalc(RecipientRegime::AuthorRoyalty, '1000', ['taxCode' => 'RSSMRA96L01H501X']);
        // VRDLGU70A01H501Z: born 1 January 1970
        $senior = wrCalc(RecipientRegime::AuthorRoyalty, '1000', ['taxCode' => 'VRDLGU70A01H501Z']);

        expect($young->flatRateDeduction->toDecimal())->toBe('400.00')
            ->and($senior->flatRateDeduction->toDecimal())->toBe('250.00');
    });

    it('falls back to the 25% deduction and warns when the age is unknown', function (): void {
        $result = wrCalc(RecipientRegime::AuthorRoyalty, '1000');

        expect($result->flatRateDeduction->toDecimal())->toBe('250.00')
            ->and($result->warnings)->toContain('birth_date_missing');
    });
});

describe('occasional self-employment (Art. 2222 c.c.)', function (): void {
    it('withholds 20% on the whole amount and charges no INPS below EUR 5,000', function (): void {
        $result = wrCalc(RecipientRegime::OccasionalWorker, '3000');

        expect($result->withholding->toDecimal())->toBe('600.00')
            ->and($result->inpsTotal->isZero())->toBeTrue()
            ->and($result->netPayable->toDecimal())->toBe('2398.00') // 3,000 - 600 - 2.00 stamp
            ->and($result->cuCausal)->toBe('M')
            ->and($result->thresholds[0]['key'])->toBe('occasional_5000')
            ->and($result->thresholds[0]['percent'])->toBe(60.0);
    });

    it('applies the EUR 2.00 revenue stamp only above EUR 77.47', function (): void {
        expect(wrCalc(RecipientRegime::OccasionalWorker, '77.47')->stampDuty->isZero())->toBeTrue()
            ->and(wrCalc(RecipientRegime::OccasionalWorker, '77.48')->stampDuty->toDecimal())->toBe('2.00');
    });

    it('charges INPS Gestione Separata only on the part above EUR 5,000, one third worker and two thirds company', function (): void {
        $result = wrCalc(RecipientRegime::OccasionalWorker, '3000', ['priorCompanyIncome' => Money::of('3000')]);

        // year total 6,000: 1,000 above the threshold at 33.72% = 337.20
        expect($result->inpsBase->toDecimal())->toBe('1000.00')
            ->and($result->inpsTotal->toDecimal())->toBe('337.20')
            ->and($result->inpsWorker->toDecimal())->toBe('112.40')
            ->and($result->inpsCompany->toDecimal())->toBe('224.80')
            ->and($result->withholding->toDecimal())->toBe('600.00')
            ->and($result->netPayable->toDecimal())->toBe('2285.60') // 3,000 - 600 - 112.40 - 2.00
            ->and($result->companyCost->toDecimal())->toBe('3224.80')
            ->and($result->warnings)->toContain('inps_threshold_exceeded');
    });

    it('counts the income self-certified from other clients towards the threshold', function (): void {
        $result = wrCalc(RecipientRegime::OccasionalWorker, '1000', ['thirdPartyIncome' => Money::of('4500')]);

        expect($result->inpsBase->toDecimal())->toBe('500.00');
    });

    it('charges INPS on the whole amount once the threshold is already passed', function (): void {
        $result = wrCalc(RecipientRegime::OccasionalWorker, '1000', ['priorCompanyIncome' => Money::of('6000')]);

        expect($result->inpsBase->toDecimal())->toBe('1000.00')
            ->and($result->inpsTotal->toDecimal())->toBe('337.20')
            ->and($result->thresholds[0]['percent'])->toBe(100.0);
    });

    it('splits the contribution so worker and company always add up to the total', function (): void {
        $result = wrCalc(RecipientRegime::OccasionalWorker, '5000.01', ['thirdPartyIncome' => Money::of('5000')]);

        expect($result->inpsWorker->plus($result->inpsCompany)->cents)->toBe($result->inpsTotal->cents);
    });
});

describe('amateur sports work (D.Lgs. 36/2021)', function (): void {
    it('is exempt from INPS and IRPEF up to EUR 5,000', function (): void {
        $result = wrCalc(RecipientRegime::AmateurSports, '4000');

        expect($result->withholding->isZero())->toBeTrue()
            ->and($result->inpsTotal->isZero())->toBeTrue()
            ->and($result->netPayable->toDecimal())->toBe('4000.00')
            ->and($result->nonTaxable->toDecimal())->toBe('4000.00')
            ->and($result->nonTaxableCode)->toBe('24');
    });

    it('charges INPS on a 50% base between EUR 5,000 and 15,000 and no IRPEF', function (): void {
        $result = wrCalc(RecipientRegime::AmateurSports, '6000', ['priorCompanyIncome' => Money::of('2000')]);

        // cumulative 2,000 -> 8,000: 3,000 above 5,000; base 50% = 1,500; 33.72% = 505.80
        expect($result->inpsBase->toDecimal())->toBe('1500.00')
            ->and($result->inpsTotal->toDecimal())->toBe('505.80')
            ->and($result->inpsWorker->toDecimal())->toBe('168.60')
            ->and($result->inpsCompany->toDecimal())->toBe('337.20')
            ->and($result->withholding->isZero())->toBeTrue()
            ->and($result->warnings)->toContain('sports_inps_threshold_exceeded');
    });

    it('withholds progressive IRPEF only on the amount above EUR 15,000', function (): void {
        $result = wrCalc(RecipientRegime::AmateurSports, '4000', ['priorCompanyIncome' => Money::of('14000')]);

        // cumulative 14,000 -> 18,000: 3,000 above 15,000 at 23%
        expect($result->taxableBase->toDecimal())->toBe('3000.00')
            ->and($result->withholding->toDecimal())->toBe('690.00')
            ->and($result->nonTaxable->toDecimal())->toBe('1000.00')
            ->and($result->inpsBase->toDecimal())->toBe('2000.00') // full 4,000 above 5,000, 50%
            ->and($result->warnings)->toContain('sports_irpef_threshold_exceeded');
    });

    it('uses the full INPS base from 2028', function (): void {
        $result = wrCalc(RecipientRegime::AmateurSports, '1000', ['priorCompanyIncome' => Money::of('6000'), 'taxYear' => 2028, 'paymentDate' => CarbonImmutable::parse('2028-03-10')]);

        expect($result->inpsBase->toDecimal())->toBe('1000.00');
    });

    it('counts the sports income certified from other associations', function (): void {
        $result = wrCalc(RecipientRegime::AmateurSports, '1000', ['thirdPartyIncome' => Money::of('5000')]);

        expect($result->inpsBase->toDecimal())->toBe('500.00');
    });

    it('applies the higher IRPEF brackets on the cumulative amount', function (): void {
        $result = wrCalc(RecipientRegime::AmateurSports, '20000', ['priorCompanyIncome' => Money::of('40000')]);

        // taxable cumulative 25,000 -> 45,000: 3,000 at 23% + 17,000 at 33%
        expect($result->withholding->toDecimal())->toBe('6300.00');
    });
});

describe('Swiss payees (Art. 85 OAVS, Art. 91-92 LIFD)', function (): void {
    it('exempts a minor wage up to CHF 2,300 a year', function (): void {
        $result = wrCalc(RecipientRegime::SwissDeMinimis, '1500');

        expect($result->avsBase->isZero())->toBeTrue()
            ->and($result->avsEmployee->isZero())->toBeTrue()
            ->and($result->netPayable->toDecimal())->toBe('1500.00')
            ->and($result->currency)->toBe('CHF')
            ->and($result->cuCausal)->toBeNull()
            ->and($result->notes)->toContain('avs_de_minimis_exempt');
    });

    it('owes AVS / AI / IPG on the whole wage once the year passes CHF 2,300, catching up the earlier payments', function (): void {
        $result = wrCalc(RecipientRegime::SwissDeMinimis, '1000', ['priorCompanyIncome' => Money::of('1500')]);

        // year total 2,500 -> base 2,500 at 10.6%: 132.50 employee, 132.50 employer
        expect($result->avsBase->toDecimal())->toBe('2500.00')
            ->and($result->avsEmployee->toDecimal())->toBe('132.50')
            ->and($result->avsEmployer->toDecimal())->toBe('132.50')
            ->and($result->netPayable->toDecimal())->toBe('867.50')
            ->and($result->companyCost->toDecimal())->toBe('1132.50')
            ->and($result->warnings)->toContain('avs_de_minimis_exceeded');
    });

    it('does not charge the earlier base twice', function (): void {
        $result = wrCalc(RecipientRegime::SwissDeMinimis, '1000', ['priorCompanyIncome' => Money::of('3000'), 'priorAvsBase' => Money::of('3000')]);

        expect($result->avsBase->toDecimal())->toBe('1000.00')
            ->and($result->avsEmployee->toDecimal())->toBe('53.00');
    });

    it('owes AVS from the first franc in the cultural and artistic sector', function (): void {
        $result = wrCalc(RecipientRegime::SwissDeMinimis, '500', ['culturalSector' => true]);

        expect($result->avsBase->toDecimal())->toBe('500.00')
            ->and($result->avsEmployee->toDecimal())->toBe('26.50')
            ->and($result->notes)->toContain('avs_cultural_sector_from_first_franc');
    });

    it('withholds the source tax of a foreign artist on the amount after the flat 50% expenses', function (): void {
        $result = wrCalc(RecipientRegime::SwissForeignArtist, '4000', ['activityType' => ActivityType::Artist, 'performanceDays' => 1]);

        // taxable 2,000 in one day: bracket 1,001-3,000 = 10%
        expect($result->acquisitionExpenses->toDecimal())->toBe('2000.00')
            ->and($result->taxableBase->toDecimal())->toBe('2000.00')
            ->and($result->withholdingRate)->toBe('10')
            ->and($result->sourceTax->toDecimal())->toBe('200.00')
            ->and($result->notes)->toContain('source_tax_flat_expenses');
    });

    it('rates the source tax on the daily amount of a multi-day engagement and uses 20% expenses for athletes', function (): void {
        $result = wrCalc(RecipientRegime::SwissForeignArtist, '10000', ['activityType' => ActivityType::Athlete, 'performanceDays' => 10, 'culturalSector' => true]);

        // expenses 2,000, taxable 8,000, 800 a day -> 5%
        expect($result->taxableBase->toDecimal())->toBe('8000.00')
            ->and($result->sourceTax->toDecimal())->toBe('400.00')
            ->and($result->avsEmployee->toDecimal())->toBe('530.00')
            ->and($result->netPayable->toDecimal())->toBe('9070.00');
    });

    it('prefers documented acquisition expenses over the flat rate', function (): void {
        $result = wrCalc(RecipientRegime::SwissForeignArtist, '4000', ['activityType' => ActivityType::Speaker, 'acquisitionExpenses' => Money::of('3500')]);

        expect($result->acquisitionExpenses->toDecimal())->toBe('3500.00')
            ->and($result->taxableBase->toDecimal())->toBe('500.00')
            ->and($result->sourceTax->toDecimal())->toBe('25.00')
            ->and($result->notes)->toContain('source_tax_documented_expenses');
    });

    it('owes no source tax on a daily amount of CHF 200 or less', function (): void {
        $result = wrCalc(RecipientRegime::SwissForeignArtist, '300', ['activityType' => ActivityType::Artist]);

        expect($result->sourceTax->isZero())->toBeTrue();
    });
});

it('has a calculator for every regime', function (): void {
    $registry = CalculatorRegistry::default();

    foreach (RecipientRegime::cases() as $regime) {
        expect($registry->for($regime)->regime())->toBe($regime);
    }
});

it('exposes the figures as decimal strings', function (): void {
    $array = wrCalc(RecipientRegime::OccasionalWorker, '1000')->toArray();

    expect($array['gross_amount'])->toBe('1000.00')
        ->and($array['net_payable'])->toBe('798.00')
        ->and($array['effective_withholding_rate'])->toBe('20.00')
        ->and($array['currency'])->toBe('EUR');
});
