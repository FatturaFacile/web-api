<?php

declare(strict_types=1);

use App\Services\Crm\Fiscal\FiscalIdentifiers;

test('italian partita iva checksum', function (): void {
    expect(FiscalIdentifiers::isValidItalianVat('01234567897'))->toBeTrue()
        ->and(FiscalIdentifiers::isValidItalianVat('IT 01234567897'))->toBeTrue()
        ->and(FiscalIdentifiers::isValidItalianVat('01234567890'))->toBeFalse()
        ->and(FiscalIdentifiers::isValidItalianVat('0123456789'))->toBeFalse()
        ->and(FiscalIdentifiers::isValidItalianVat('00000000000'))->toBeFalse()
        ->and(FiscalIdentifiers::isValidItalianVat('ABCDEFGHIJK'))->toBeFalse();
});

test('italian codice fiscale: personal code checksum, case and company form', function (): void {
    expect(FiscalIdentifiers::isValidItalianFiscalCode('RSSMRA85T10A562S'))->toBeTrue()
        ->and(FiscalIdentifiers::isValidItalianFiscalCode('rssmra85t10a562s'))->toBeTrue()
        ->and(FiscalIdentifiers::isValidItalianFiscalCode('RSSMRA85T10A562X'))->toBeFalse()
        ->and(FiscalIdentifiers::isValidItalianFiscalCode('RSSMRA85T10A562'))->toBeFalse()
        ->and(FiscalIdentifiers::isValidItalianFiscalCode('01234567897'))->toBeTrue()
        ->and(FiscalIdentifiers::isValidItalianFiscalCode('01234567890'))->toBeFalse();
});

test('codice fiscale accepts omocodia substitutions with a recomputed check letter', function (): void {
    // Base code RSSMRA85T10A562S: replace the last digit of the birth place (2 -> N) and recompute the control letter.
    $base = 'RSSMRA85T10A56N';
    $odd = [1, 0, 5, 7, 9, 13, 15, 17, 19, 21, 2, 4, 18, 20, 11, 3, 6, 8, 12, 14, 16, 10, 22, 25, 24, 23];
    $sum = 0;

    foreach (str_split($base) as $i => $char) {
        $index = ctype_digit($char) ? (int) $char : ord($char) - 65;
        $sum += $i % 2 === 0 ? $odd[$index] : $index;
    }

    expect(FiscalIdentifiers::isValidItalianFiscalCode($base.chr(65 + $sum % 26)))->toBeTrue();
});

test('codice fiscale rejects impossible birth dates', function (): void {
    // 31st of April with an otherwise consistent shape must be rejected even if the check letter happens to match.
    expect(FiscalIdentifiers::isValidItalianFiscalCode('RSSMRA85D31A562S'))->toBeFalse();
});

test('swiss uid modulo 11 checksum and formats', function (): void {
    expect(FiscalIdentifiers::isValidSwissUid('CHE-123.456.788'))->toBeTrue()
        ->and(FiscalIdentifiers::isValidSwissUid('CHE123456788'))->toBeTrue()
        ->and(FiscalIdentifiers::isValidSwissUid('che 123.456.788 MWST'))->toBeTrue()
        ->and(FiscalIdentifiers::isValidSwissUid('CHE-123.456.789'))->toBeFalse()
        ->and(FiscalIdentifiers::isValidSwissUid('CHE-123.456'))->toBeFalse()
        ->and(FiscalIdentifiers::normalizeSwissUid('CHE123456788 TVA'))->toBe('CHE-123.456.788');
});

test('sdi recipient code is 7 alphanumeric characters', function (): void {
    expect(FiscalIdentifiers::isValidSdiCode('M5UXCR1'))->toBeTrue()
        ->and(FiscalIdentifiers::isValidSdiCode('m5uxcr1'))->toBeTrue()
        ->and(FiscalIdentifiers::isValidSdiCode('0000000'))->toBeTrue()
        ->and(FiscalIdentifiers::isValidSdiCode('M5UXCR'))->toBeFalse()
        ->and(FiscalIdentifiers::isValidSdiCode('M5UX-R1'))->toBeFalse();
});
