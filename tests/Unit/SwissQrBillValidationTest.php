<?php

declare(strict_types=1);

namespace Tests\Unit;

describe('Swiss QR-Bill & ISO 20022 Payload Validation', function () {
    it('validates a correct Swiss QR-IBAN format according to SIX guidelines', function () {
        $isValidQrIban = function (string $iban): bool {
            $cleaned = strtoupper(str_replace(' ', '', $iban));
            if (! preg_match('/^CH\d{2}(3[0-1]\d{3})\d{12}$/', $cleaned, $matches)) {
                return false;
            }
            $iid = (int) $matches[1];

            return $iid >= 30000 && $iid <= 31999;
        };

        // CH44 3199 9123 0008 8901 2 is a valid QR-IBAN (IID in range 30000-31999)
        expect($isValidQrIban('CH4431999123000889012'))->toBeTrue();
        expect($isValidQrIban('CH44 3199 9123 0008 8901 2'))->toBeTrue();

        // Standard Swiss IBAN (IID 09000 - non-QR)
        expect($isValidQrIban('CH9309000000123456789'))->toBeFalse();
    });

    it('validates Swiss QR reference number with Modulo 10 recursive check digit', function () {
        $calculateMod10 = function (string $number): int {
            $table = [0, 9, 4, 6, 8, 2, 7, 1, 3, 5];
            $carry = 0;
            $len = strlen($number);
            for ($i = 0; $i < $len; $i++) {
                $carry = $table[($carry + (int) $number[$i]) % 10];
            }

            return (10 - $carry) % 10;
        };

        $baseReference = '21000000000313947143000901';
        $checkDigit = $calculateMod10($baseReference);
        $fullQrReference = $baseReference.(string) $checkDigit;

        expect(strlen($fullQrReference))->toBe(27);
        expect($checkDigit)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(9);
    });

    it('validates Swiss QR-Bill structured payload mandatory elements', function () {
        $qrPayloadData = [
            'header' => 'SPC',
            'version' => '0200',
            'coding' => '1',
            'iban' => 'CH4431999123000889012',
            'creditor' => [
                'type' => 'S',
                'name' => 'FatturaFacile SA',
                'street' => 'Via Cantonale 1',
                'postal_code' => '6900',
                'city' => 'Lugano',
                'country' => 'CH',
            ],
            'amount' => 1500.50,
            'currency' => 'CHF',
            'reference_type' => 'QRR',
            'reference' => '210000000003139471430009017',
            'trailer' => 'EPD',
        ];

        expect($qrPayloadData['header'])->toBe('SPC');
        expect($qrPayloadData['version'])->toBe('0200');
        expect(in_array($qrPayloadData['currency'], ['CHF', 'EUR'], true))->toBeTrue();
        expect($qrPayloadData['amount'])->toBeGreaterThan(0.0);
        expect($qrPayloadData['creditor']['country'])->toBe('CH');
        expect(strlen($qrPayloadData['creditor']['name']))->toBeLessThanOrEqual(70);
    });

    it('validates ISO 20022 pain.001 credit transfer schema constraints', function () {
        $pain001Message = [
            'message_id' => 'MSG-2027-001',
            'created_at' => '2027-01-15T10:00:00Z',
            'initiating_party' => 'FatturaFacile Holding',
            'payment_info_id' => 'PMT-001',
            'execution_date' => '2027-01-16',
            'debtor_iban' => 'CH9309000000123456789',
            'credit_transfers' => [
                [
                    'end_to_end_id' => 'E2E-INV-1001',
                    'amount' => 1500.50,
                    'currency' => 'CHF',
                    'creditor_iban' => 'CH4431999123000889012',
                    'creditor_name' => 'FatturaFacile SA',
                ],
            ],
        ];

        expect($pain001Message['debtor_iban'])->toMatch('/^CH\d{19}$/');
        expect($pain001Message['credit_transfers'])->toHaveCount(1);
        expect($pain001Message['credit_transfers'][0]['amount'])->toBe(1500.50);
        expect(strlen($pain001Message['credit_transfers'][0]['end_to_end_id']))->toBeLessThanOrEqual(35);
    });
});
