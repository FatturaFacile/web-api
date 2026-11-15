<?php

declare(strict_types=1);

use App\Exceptions\FiscalRuleException;
use App\Services\Invoicing\Tax\EnasarcoSettings;
use App\Services\Invoicing\Tax\FiscalInputResolver;
use App\Services\Invoicing\Tax\FiscalInput;
use App\Services\Invoicing\Tax\FiscalLine;
use App\Services\Invoicing\Tax\FundSettings;
use App\Services\Invoicing\Tax\ItalianFiscalCalculator;
use App\Services\Invoicing\Tax\WithholdingSettings;
use App\Support\Money;

beforeEach(function (): void {
    $this->calculator = new ItalianFiscalCalculator;
});

/**
 * @param  array<string, mixed>  $overrides
 */
function calcInput(array $lines, array $overrides = []): FiscalInput
{
    return new FiscalInput(...array_merge(['lines' => $lines], $overrides));
}

function calcLine(string $price, string $rate = '22', ?string $natura = null, string $quantity = '1', string $discount = '0', bool $withholding = true, bool $fund = true): FiscalLine
{
    return new FiscalLine($quantity, $price, $rate, $discount, $natura, $withholding, $fund);
}

/**
 * Asserts a FiscalRuleException with the given code and field.
 */
function expectFiscalRule(callable $callback, string $code, string $field): void
{
    try {
        $callback();
    } catch (FiscalRuleException $exception) {
        expect($exception->errorCode)->toBe($code)->and($exception->field)->toBe($field);

        return;
    }

    test()->fail("Expected FiscalRuleException [{$code}] was not thrown.");
}

// ------------------------------------------------------------ Money helpers

test('money helpers convert and round half-up in integer cents', function (): void {
    expect(Money::toCents('1.005'))->toBe(101)
        ->and(Money::toCents(0.1))->toBe(10)
        ->and(Money::toCents(1.005))->toBe(101)
        ->and(Money::toCents('-2.345'))->toBe(-235)
        ->and(Money::toCents('1000'))->toBe(100000)
        ->and(Money::toCents(null))->toBe(0)
        ->and(Money::toCents('1e3'))->toBe(100000)
        ->and(Money::fromCents(106880))->toBe('1068.80')
        ->and(Money::fromCents(-5))->toBe('-0.05')
        ->and(Money::fromCents(0))->toBe('0.00')
        ->and(Money::fromScaled(2200, 2))->toBe('22.00')
        ->and(Money::percentOf(1, '50'))->toBe(1)
        ->and(Money::percentOf(104000, '22'))->toBe(22880)
        ->and(Money::divideRounded(-5, 10))->toBe(-1)
        ->and(Money::multiplyToCents(Money::toScaled('3', 4), 4, Money::toScaled('0.3333', 4), 4))->toBe(100);
});

test('money rejects malformed amounts', function (): void {
    Money::toCents('12,50');
})->throws(InvalidArgumentException::class);

// ------------------------------------------------------------ Professional: rivalsa INPS + ritenuta

test('professional invoice with rivalsa INPS 4% and ritenuta 20% on the service only', function (): void {
    $result = $this->calculator->calculate(calcInput([calcLine('1000.00')], [
        'fund' => new FundSettings('TC22', '4'),
        'withholding' => new WithholdingSettings('RT01', 'A', '20'),
    ]));

    expect($result->net)->toBe(100000)
        ->and($result->fund)->toBe(4000)
        ->and($result->fundRate)->toBe(2200)
        ->and($result->vatSummary)->toBe([['rate' => 2200, 'natura' => null, 'taxable' => 104000, 'tax' => 22880, 'payability' => 'I']])
        ->and($result->vat)->toBe(22880)
        ->and($result->gross)->toBe(126880)
        ->and($result->withholdingBase)->toBe(100000)
        ->and($result->withholding)->toBe(20000)
        ->and($result->due)->toBe(106880)
        ->and($result->stampDutyApplied)->toBeFalse()
        ->and($result->toArray()['totals'])->toBe([
            'net' => '1000.00',
            'fund' => '40.00',
            'taxable_vat' => '1040.00',
            'vat' => '228.80',
            'stamp_duty' => '0.00',
            'gross' => '1268.80',
            'withholding' => '200.00',
            'enasarco' => '0.00',
            'split_payment_vat' => '0.00',
            'due' => '1068.80',
        ]);
});

test('ritenuta including the cassa when withholding_tax_includes_fund is set', function (): void {
    $result = $this->calculator->calculate(calcInput([calcLine('1000.00')], [
        'fund' => new FundSettings('TC22', '4'),
        'withholding' => new WithholdingSettings('RT01', 'A', '20', '100', includesFund: true),
    ]));

    expect($result->withholdingBase)->toBe(104000)
        ->and($result->withholding)->toBe(20800)
        ->and($result->gross)->toBe(126880)
        ->and($result->due)->toBe(106080);
});

test('the fund setting withholding_applies also puts the cassa in the ritenuta base', function (): void {
    $result = $this->calculator->calculate(calcInput([calcLine('1000.00')], [
        'fund' => new FundSettings('TC22', '4', withholdingApplies: true),
        'withholding' => new WithholdingSettings('RT01', 'A', '20'),
    ]));

    expect($result->withholding)->toBe(20800)->and($result->due)->toBe(106080);
});

test('lines excluded from the ritenuta and the cassa (e.g. art. 15 reimbursements) stay out of both bases', function (): void {
    $result = $this->calculator->calculate(calcInput([
        calcLine('1000.00'),
        calcLine('50.00', '0', 'N1', withholding: false, fund: false),
    ], [
        'fund' => new FundSettings('TC22', '4'),
        'withholding' => new WithholdingSettings('RT01', 'A', '20'),
    ]));

    expect($result->fundBase)->toBe(100000)
        ->and($result->fund)->toBe(4000)
        ->and($result->withholding)->toBe(20000)
        ->and($result->vat)->toBe(22880)
        ->and($result->gross)->toBe(131880)
        ->and($result->due)->toBe(111880);
});

// ------------------------------------------------------------ Agent: 23% on 50%

test('agent ritenuta 23% on 50% of the commissions', function (): void {
    $result = $this->calculator->calculate(calcInput([calcLine('1000.00')], [
        'withholding' => new WithholdingSettings('RT01', 'R', '23', '50'),
    ]));

    expect($result->withholdingBase)->toBe(50000)
        ->and($result->withholding)->toBe(11500)
        ->and($result->gross)->toBe(122000)
        ->and($result->due)->toBe(110500);
});

// ------------------------------------------------------------ ENASARCO cap

test('enasarco is applied only up to the annual commission ceiling', function (): void {
    $result = $this->calculator->calculate(calcInput([calcLine('3000.00')], [
        'enasarco' => new EnasarcoSettings('4.00', '100000.00', '99000.00'),
    ]));

    expect($result->enasarcoCommissions)->toBe(300000)
        ->and($result->enasarcoTaxable)->toBe(100000)
        ->and($result->enasarco)->toBe(4000)
        ->and($result->gross)->toBe(366000)
        ->and($result->due)->toBe(362000);
});

test('enasarco contributory base is zero once the ceiling is reached', function (): void {
    $result = $this->calculator->calculate(calcInput([calcLine('3000.00')], [
        'enasarco' => new EnasarcoSettings('4.00', '100000.00', '100500.00'),
    ]));

    expect($result->enasarcoTaxable)->toBe(0)->and($result->enasarco)->toBe(0);
});

// ------------------------------------------------------------ Split payment

test('split payment: VAT is shown with payability S and excluded from the amount due', function (): void {
    $result = $this->calculator->calculate(calcInput([calcLine('1000.00')], ['splitPayment' => true]));

    expect($result->vat)->toBe(22000)
        ->and($result->vatSummary[0]['payability'])->toBe('S')
        ->and($result->gross)->toBe(122000)
        ->and($result->splitPaymentVat)->toBe(22000)
        ->and($result->due)->toBe(100000);
});

test('split payment with ritenuta subtracts both from the amount due', function (): void {
    $result = $this->calculator->calculate(calcInput([calcLine('1000.00')], [
        'splitPayment' => true,
        'withholding' => new WithholdingSettings('RT01', 'A', '20'),
    ]));

    expect($result->due)->toBe(80000);
});

test('split payment cannot be combined with reverse charge', function (): void {
    expectFiscalRule(fn () => $this->calculator->calculate(calcInput([calcLine('1000.00')], ['splitPayment' => true, 'reverseCharge' => true])), 'split_payment_incompatible', 'split_payment');
});

test('split payment requires at least one line with VAT', function (): void {
    expectFiscalRule(fn () => $this->calculator->calculate(calcInput([calcLine('1000.00', '0', 'N4')], ['splitPayment' => true])), 'split_payment_requires_vat', 'split_payment');
});

// ------------------------------------------------------------ Bollo

test('virtual stamp duty applies strictly above 77.47 of amounts without VAT', function (string $amount, bool $applied, int $gross): void {
    $result = $this->calculator->calculate(calcInput([calcLine($amount, '0', 'N4')]));

    expect($result->stampDutyBase)->toBe(Money::toCents($amount))
        ->and($result->stampDutyApplied)->toBe($applied)
        ->and($result->stampDuty)->toBe($applied ? 200 : 0)
        ->and($result->gross)->toBe($gross);
})->with([
    'at the threshold' => ['77.47', false, 7747],
    'one cent above' => ['77.48', true, 7948],
]);

test('stamp duty on mixed lines is computed on the exempt part only', function (): void {
    $result = $this->calculator->calculate(calcInput([calcLine('500.00'), calcLine('100.00', '0', 'N4')]));

    expect($result->stampDutyBase)->toBe(10000)
        ->and($result->stampDutyApplied)->toBeTrue()
        ->and($result->vatSummary)->toBe([
            ['rate' => 2200, 'natura' => null, 'taxable' => 50000, 'tax' => 11000, 'payability' => 'I'],
            ['rate' => 0, 'natura' => 'N4', 'taxable' => 10000, 'tax' => 0, 'payability' => null],
        ])
        ->and($result->gross)->toBe(71200);
});

test('stamp duty paid by the issuer is applied but not added to the total', function (): void {
    $result = $this->calculator->calculate(calcInput([calcLine('100.00', '0', 'N4')], ['stampDutyChargedToCustomer' => false]));

    expect($result->stampDutyApplied)->toBeTrue()
        ->and($result->stampDuty)->toBe(200)
        ->and($result->stampDutyChargedToCustomer)->toBeFalse()
        ->and($result->gross)->toBe(10000)
        ->and($result->due)->toBe(10000);
});

test('lines with natura outside the bollo list (reverse charge) do not trigger stamp duty', function (): void {
    $result = $this->calculator->calculate(calcInput([calcLine('1000.00', '22')], ['reverseCharge' => true]));

    expect($result->lines[0])->toBe(['net' => 100000, 'rate' => 0, 'natura' => 'N6.9'])
        ->and($result->vat)->toBe(0)
        ->and($result->stampDutyApplied)->toBeFalse()
        ->and($result->gross)->toBe(100000);
});

// ------------------------------------------------------------ Forfettario

test('forfettario: lines become N2.2 without VAT, stamp duty and legal wording are added', function (): void {
    $result = $this->calculator->calculate(calcInput([calcLine('100.00', '22')], ['regimeFiscale' => 'RF19']));

    expect($result->lines[0])->toBe(['net' => 10000, 'rate' => 0, 'natura' => 'N2.2'])
        ->and($result->vatSummary)->toBe([['rate' => 0, 'natura' => 'N2.2', 'taxable' => 10000, 'tax' => 0, 'payability' => null]])
        ->and($result->vat)->toBe(0)
        ->and($result->stampDutyApplied)->toBeTrue()
        ->and($result->stampDuty)->toBe(200)
        ->and($result->gross)->toBe(10200)
        ->and($result->due)->toBe(10200)
        ->and($result->withholding)->toBe(0)
        ->and($result->legalNotices)->toBe(config('italian_tax.forfettario.legal_notices'));
});

test('forfettario with rivalsa INPS keeps the cassa out of VAT', function (): void {
    $result = $this->calculator->calculate(calcInput([calcLine('1000.00', '22')], [
        'regimeFiscale' => 'RF19',
        'fund' => new FundSettings('TC22', '4'),
    ]));

    expect($result->fund)->toBe(4000)
        ->and($result->fundNatura)->toBe('N2.2')
        ->and($result->vatSummary)->toBe([['rate' => 0, 'natura' => 'N2.2', 'taxable' => 104000, 'tax' => 0, 'payability' => null]])
        ->and($result->gross)->toBe(104200);
});

test('forfettario rejects ritenuta, split payment and reverse charge', function (string $option, string $field): void {
    $overrides = match ($option) {
        'withholding' => ['withholding' => new WithholdingSettings('RT01', 'A', '20')],
        'split' => ['splitPayment' => true],
        default => ['reverseCharge' => true],
    };

    expectFiscalRule(fn () => $this->calculator->calculate(calcInput([calcLine('100.00')], array_merge(['regimeFiscale' => 'RF19'], $overrides))), 'forfettario_incompatible', $field);
})->with([
    'ritenuta' => ['withholding', 'withholding'],
    'split payment' => ['split', 'split_payment'],
    'reverse charge' => ['reverse', 'reverse_charge'],
]);

// ------------------------------------------------------------ Rounding

test('VAT is rounded once per aggregate, not per line', function (): void {
    // Three lines of 0.10 at 22%: per line 0.022 -> 0.02 (x3 = 0.06); per aggregate 0.30 * 22% = 0.066 -> 0.07.
    $result = $this->calculator->calculate(calcInput([calcLine('0.10'), calcLine('0.10'), calcLine('0.10')]));

    expect($result->vat)->toBe(7)->and($result->gross)->toBe(37);
});

test('line nets and percentages round half-up to the cent', function (string $quantity, string $price, string $discount, int $net, int $vat): void {
    $result = $this->calculator->calculate(calcInput([calcLine($price, '22', null, $quantity, $discount)]));

    expect($result->net)->toBe($net)->and($result->vat)->toBe($vat);
})->with([
    'one third of a cent in the quantity' => ['3', '0.3333', '0', 100, 22],
    'price with four decimals' => ['1', '10.3350', '0', 1034, 227],
    'discount of 33.33%' => ['1', '100.00', '33.33', 6667, 1467],
    'half cent VAT rounds up' => ['1', '0.25', '0', 25, 6],
]);

test('an invalid natura code is rejected', function (): void {
    expectFiscalRule(fn () => $this->calculator->calculate(calcInput([calcLine('10.00', '0', 'N9')])), 'invalid_natura', 'items.0.natura');
});

test('a 0% line without natura produces a warning', function (): void {
    $result = $this->calculator->calculate(calcInput([calcLine('10.00', '0')]));

    expect($result->warnings)->toHaveCount(1);
});

test('the input resolver ignores empty option objects and maps payability S to split payment', function (): void {
    $input = app(FiscalInputResolver::class)->resolve([
        'items' => [['quantity' => 1, 'unit_price' => 100, 'tax_rate' => 22]],
        'fund' => [],
        'withholding' => ['type' => 'RT01', 'reason' => 'A', 'rate' => 20, 'enabled' => false],
        'vat_payability' => 'S',
    ], null);

    expect($input->fund)->toBeNull()
        ->and($input->withholding)->toBeNull()
        ->and($input->enasarco)->toBeNull()
        ->and($input->splitPayment)->toBeTrue()
        ->and($input->regimeFiscale)->toBe('RF01')
        ->and($input->stampDutyChargedToCustomer)->toBeTrue();
});

// ------------------------------------------------------------ Range guard

test('amounts beyond the integer-cent range are a fiscal rule error, not an overflow', function (): void {
    expectFiscalRule(fn () => $this->calculator->calculate(calcInput([calcLine('99999999999999.00', '22', null, '99999999999')])), 'amount_out_of_range', 'items');
});
