<?php

declare(strict_types=1);

use Modules\SalesBoundedContext\Domain\ValueObjects\DecimalMoney;
use Modules\SalesBoundedContext\Domain\ValueObjects\FiscalIdentity;
use Modules\SalesBoundedContext\Exceptions\SalesBoundedContextException;

it('performs high-precision decimal math, cascading discounts, pro-rata, margin, and Swiss 0.05 CHF rounding', function (): void {
    $price = DecimalMoney::of('1000.0000', 'CHF');
    $discounted = $price->applyCascadingDiscounts([10, 5, 2]);
    // 1000 * 0.90 * 0.95 * 0.98 = 837.90
    expect($discounted->roundHalfUp())->toBe('837.90');

    $cost = DecimalMoney::of('600.0000', 'CHF');
    expect($discounted->marginPercentage($cost))->toBe(28.39);

    $proRata = DecimalMoney::of('300.00', 'CHF')->allocateProRata(15, 30);
    expect($proRata->roundHalfUp())->toBe('150.00');

    expect(DecimalMoney::of('10.03', 'CHF')->roundSwissFiveRappen())->toBe('10.05')
        ->and(DecimalMoney::of('10.02', 'CHF')->roundSwissFiveRappen())->toBe('10.00');
});

it('validates Swiss UID and QR-IBAN and Italian P.IVA, Codice Fiscale, SDI, and PEC', function (): void {
    $ch = FiscalIdentity::fromArray([
        'country' => 'CH',
        'uid_number' => 'CHE116281710',
        'qr_iban' => 'CH4431999123000889012',
    ]);
    expect($ch->uidNumber)->toBe('CHE-116.281.710')
        ->and($ch->qrIban)->toBe('CH4431999123000889012');

    $it = FiscalIdentity::fromArray([
        'country' => 'IT',
        'vat_number' => 'IT08923410966',
        'fiscal_code' => '08923410966',
        'sdi_code' => 'm5uxcr1',
        'pec_email' => 'PEC@EXAMPLE.IT',
    ]);
    expect($it->sdiCode)->toBe('M5UXCR1')
        ->and($it->pecEmail)->toBe('pec@example.it');

    $qrRef = FiscalIdentity::buildSwissQrReference('20261125001');
    expect(strlen($qrRef))->toBe(27);

    expect(static fn () => FiscalIdentity::fromArray([
        'country' => 'CH',
        'uid_number' => 'CHE-116.281.719',
    ]))->toThrow(SalesBoundedContextException::class);
});
