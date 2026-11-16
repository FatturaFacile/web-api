<?php

declare(strict_types=1);

namespace Tests\Unit;

describe('Fiscal VAT/MWST Calculations & Rounding Rules', function () {
    it('calculates Italian standard IVA at 22% and applies commercial half-up rounding', function () {
        $netAmount = 125.45;
        $vatRate = 22.0;

        $calculatedVat = round($netAmount * ($vatRate / 100), 2);
        $totalGross = round($netAmount + $calculatedVat, 2);

        expect($calculatedVat)->toBe(27.60);
        expect($totalGross)->toBe(153.05);
    });

    it('calculates Italian reduced rates (10%, 5%, 4%) accurately without drift', function () {
        $items = [
            ['net' => 45.10, 'rate' => 10.0, 'expected_vat' => 4.51],
            ['net' => 99.99, 'rate' => 5.0,  'expected_vat' => 5.00],
            ['net' => 33.33, 'rate' => 4.0,  'expected_vat' => 1.33],
        ];

        foreach ($items as $item) {
            $vat = round($item['net'] * ($item['rate'] / 100), 2);
            expect($vat)->toBe($item['expected_vat']);
        }
    });

    it('calculates Swiss MWST rates (8.1% standard, 2.6% reduced, 3.8% accommodation) according to FTA rules', function () {
        $netStandard = 1000.00;
        $standardRate = 8.1;
        $standardVat = round($netStandard * ($standardRate / 100), 2);
        expect($standardVat)->toBe(81.00);

        $netReduced = 250.75;
        $reducedRate = 2.6;
        $reducedVat = round($netReduced * ($reducedRate / 100), 2);
        expect($reducedVat)->toBe(6.52);

        $netAccom = 420.00;
        $accomRate = 3.8;
        $accomVat = round($netAccom * ($accomRate / 100), 2);
        expect($accomVat)->toBe(15.96);
    });

    it('handles line-level vs document-level aggregation rounding differences correctly', function () {
        $line1Net = 10.555;
        $line2Net = 20.444;
        $rate = 22.0;

        $line1RoundedNet = round($line1Net, 2); // 10.56
        $line2RoundedNet = round($line2Net, 2); // 20.44

        $line1Vat = round($line1RoundedNet * ($rate / 100), 2); // 2.32
        $line2Vat = round($line2RoundedNet * ($rate / 100), 2); // 4.50
        $sumVat = round($line1Vat + $line2Vat, 2); // 6.82

        $totalNet = round($line1RoundedNet + $line2RoundedNet, 2); // 31.00
        $docVat = round($totalNet * ($rate / 100), 2); // 6.82

        expect($sumVat)->toBe($docVat);
    });

    it('correctly rounds Swiss Francs cash transactions to the nearest 0.05 CHF (5 Rappen)', function () {
        $roundToFiveRappen = function (float $amount): float {
            return round($amount * 20.0) / 20.0;
        };

        expect($roundToFiveRappen(100.02))->toBe(100.00);
        expect($roundToFiveRappen(100.03))->toBe(100.05);
        expect($roundToFiveRappen(100.07))->toBe(100.05);
        expect($roundToFiveRappen(100.08))->toBe(100.10);
    });
});
