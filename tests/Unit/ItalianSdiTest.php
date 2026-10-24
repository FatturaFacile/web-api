<?php

declare(strict_types=1);

use App\Services\ItalianSdi\FatturaPaBuilderService;

beforeEach(function (): void {
    $this->sdiService = new FatturaPaBuilderService;
});

test('it validates italian partita iva format and luhn checksum', function (): void {
    // Valid Italian P.IVA (e.g. Enel 00811720580)
    expect($this->sdiService->isValidPartitaIva('00811720580'))->toBeTrue();

    // Invalid length
    expect($this->sdiService->isValidPartitaIva('1234567890'))->toBeFalse();

    // Invalid checksum digit
    expect($this->sdiService->isValidPartitaIva('00811720581'))->toBeFalse();

    // All zeroes
    expect($this->sdiService->isValidPartitaIva('00000000000'))->toBeFalse();
});

test('it validates italian codice fiscale with control character', function (): void {
    // Valid CF example: RSSMRA85M01H501Q (Mario Rossi, 01/08/1985, Roma)
    expect($this->sdiService->isValidCodiceFiscale('RSSMRA85M01H501Q'))->toBeTrue();

    // Invalid control character (last char should be Q, not A)
    expect($this->sdiService->isValidCodiceFiscale('RSSMRA85M01H501A'))->toBeFalse();

    // Invalid length
    expect($this->sdiService->isValidCodiceFiscale('RSSMRA85M01H50'))->toBeFalse();
});

test('it validates sdi recipient code format', function (): void {
    expect($this->sdiService->isValidSdiCode('M5UXCR1'))->toBeTrue();
    expect($this->sdiService->isValidSdiCode('0000000'))->toBeTrue();
    expect($this->sdiService->isValidSdiCode('TOO_LONG_CODE'))->toBeFalse();
    expect($this->sdiService->isValidSdiCode('12345'))->toBeFalse();
});
