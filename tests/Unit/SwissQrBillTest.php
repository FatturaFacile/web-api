<?php

declare(strict_types=1);

use App\Services\SwissQrBill\QrBillGeneratorService;

beforeEach(function (): void {
    $this->qrService = new QrBillGeneratorService;
});

test('it calculates modulo 10 recursive checksum and validates scor reference', function (): void {
    // Test base reference number
    $baseSeed = '21000000000313947143000901';
    $scor = $this->qrService->generateScorReference($baseSeed);

    expect(strlen($scor))->toBe(27)
        ->and($this->qrService->validateScorReference($scor))->toBeTrue();

    // Modifying any single digit should fail validation
    $tampered = substr($scor, 0, 26).((((int) substr($scor, 26, 1)) + 1) % 10);
    expect($this->qrService->validateScorReference($tampered))->toBeFalse();
});

test('it formats scor reference into standard 5 character blocks', function (): void {
    $scor = '210000000003139471430009017';
    $formatted = $this->qrService->formatScorReference($scor);

    expect($formatted)->toBe('21 00000 00003 13947 14300 09017');
});

test('it enforces swiss 0.05 chf rounding rule accurately', function (): void {
    expect($this->qrService->roundChfAmount(123.41))->toBe(123.40)
        ->and($this->qrService->roundChfAmount(123.42))->toBe(123.40)
        ->and($this->qrService->roundChfAmount(123.43))->toBe(123.45)
        ->and($this->qrService->roundChfAmount(123.47))->toBe(123.45)
        ->and($this->qrService->roundChfAmount(123.48))->toBe(123.50);

    expect($this->qrService->computeRoundingDifference(123.42))->toBe(-0.02)
        ->and($this->qrService->computeRoundingDifference(123.48))->toBe(0.02);
});

test('it validates swiss qr-iban vs ordinary iban', function (): void {
    // Swiss QR-IBANs must have IID between 30000 and 31999
    // Ordinary Credit Suisse / UBS IBAN (IID 04835) is not a QR-IBAN
    $ordinaryIban = 'CH93 0076 2011 6238 5295 7'; // Raiffeisen ordinary
    expect($this->qrService->isValidQrIban($ordinaryIban))->toBeFalse();

    // Standard valid QR-IBAN (IID 31999)
    $validQrIban = 'CH44 3199 9123 0008 8901 2';
    expect($this->qrService->isValidQrIban($validQrIban))->toBeTrue();
});

test('it assembles valid six group swiss payments code payload', function (): void {
    $payload = $this->qrService->assembleQrPayload(
        qrIban: 'CH4431999123000889012',
        creditor: [
            'name' => 'FatturaFacile Swiss SA',
            'street' => 'Via Nassa',
            'house_number' => '5',
            'postal_code' => '6900',
            'city' => 'Lugano',
            'country' => 'CH',
        ],
        amount: 1500.23, // CHF rounded will be 1500.25
        currency: 'CHF',
        debtor: [
            'name' => 'Client SA',
            'street' => 'Bahnhofstrasse',
            'house_number' => '12',
            'postal_code' => '8001',
            'city' => 'Zürich',
            'country' => 'CH',
        ],
        scorReference: '210000000003139471430009017',
        unstructuredMessage: 'Fattura INV-2026-001'
    );

    expect($payload)->toContain('SPC')
        ->and($payload)->toContain('0200')
        ->and($payload)->toContain('CH4431999123000889012')
        ->and($payload)->toContain('1500.25') // 0.05 rounding applied
        ->and($payload)->toContain('CHF')
        ->and($payload)->toContain('QRR')
        ->and($payload)->toContain('210000000003139471430009017')
        ->and($payload)->toContain('EPD');
});
