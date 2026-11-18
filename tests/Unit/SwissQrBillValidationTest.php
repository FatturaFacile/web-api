<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\SwissQrBill\QrBillGeneratorService;

describe('Swiss QR-Bill & ISO 20022 Payload Validation', function () {
    it('validates a correct Swiss QR-IBAN format according to SIX guidelines via QrBillGeneratorService', function () {
        $service = new QrBillGeneratorService;

        expect($service->isValidQrIban('CH4431999123000889012'))->toBeTrue()
            ->and($service->isValidQrIban('CH44 3199 9123 0008 8901 2'))->toBeTrue()
            ->and($service->isValidQrIban('CH9300762011623852957'))->toBeFalse();
    });

    it('validates Swiss QR reference number with Modulo 10 recursive check digit via QrBillGeneratorService', function () {
        $service = new QrBillGeneratorService;
        $baseReference = '21000000000313947143000901';
        $checkDigit = $service->calculateMod10RecursiveCheckDigit($baseReference);
        $fullQrReference = $baseReference.(string) $checkDigit;

        expect(strlen($fullQrReference))->toBe(27)
            ->and($checkDigit)->toBe(7)
            ->and($service->generateScorReference($baseReference))->toBe($fullQrReference);
    });
});
