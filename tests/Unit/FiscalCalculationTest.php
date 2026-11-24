<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Invoicing\Tax\FiscalInput;
use App\Services\Invoicing\Tax\FiscalLine;
use App\Services\Invoicing\Tax\ItalianFiscalCalculator;
use App\Services\SwissQrBill\QrBillGeneratorService;

describe('Fiscal VAT/MWST Calculations & Rounding Rules', function () {
    it('calculates Italian standard IVA at 22% and applies commercial half-up rounding via ItalianFiscalCalculator', function () {
        $calculator = new ItalianFiscalCalculator;
        $totals = $calculator->calculate(new FiscalInput(
            lines: [new FiscalLine(quantity: 1, unitPrice: 125.45, taxRate: 22.0)],
        ))->toArray()['totals'];

        expect((float) $totals['net'])->toBe(125.45)
            ->and((float) $totals['vat'])->toBe(27.60)
            ->and((float) $totals['gross'])->toBe(153.05);
    });

    it('calculates Italian reduced rates (10%, 5%, 4%) accurately without drift via ItalianFiscalCalculator', function () {
        $calculator = new ItalianFiscalCalculator;
        $items = [
            ['net' => 45.10, 'rate' => 10.0, 'expected_vat' => 4.51],
            ['net' => 99.99, 'rate' => 5.0,  'expected_vat' => 5.00],
            ['net' => 33.33, 'rate' => 4.0,  'expected_vat' => 1.33],
        ];

        foreach ($items as $item) {
            $totals = $calculator->calculate(new FiscalInput(
                lines: [new FiscalLine(quantity: 1, unitPrice: $item['net'], taxRate: $item['rate'])],
            ))->toArray()['totals'];
            expect((float) $totals['vat'])->toBe($item['expected_vat']);
        }
    });

    it('calculates Swiss MWST rates (8.1% standard, 2.6% reduced, 3.8% accommodation) and 0.05 CHF rounding via domain services', function () {
        $calculator = new ItalianFiscalCalculator;
        $qrService = new QrBillGeneratorService;

        $standard = $calculator->calculate(new FiscalInput(
            lines: [new FiscalLine(quantity: 1, unitPrice: 1000.00, taxRate: 8.1)],
        ))->toArray()['totals'];
        expect((float) $standard['vat'])->toBe(81.00);

        $reduced = $calculator->calculate(new FiscalInput(
            lines: [new FiscalLine(quantity: 1, unitPrice: 250.75, taxRate: 2.6)],
        ))->toArray()['totals'];
        expect((float) $reduced['vat'])->toBe(6.52);

        $accom = $calculator->calculate(new FiscalInput(
            lines: [new FiscalLine(quantity: 1, unitPrice: 420.00, taxRate: 3.8)],
        ))->toArray()['totals'];
        expect((float) $accom['vat'])->toBe(15.96);

        expect($qrService->roundChfAmount(100.02))->toBe(100.00)
            ->and($qrService->roundChfAmount(100.03))->toBe(100.05)
            ->and($qrService->roundChfAmount(100.07))->toBe(100.05)
            ->and($qrService->roundChfAmount(100.08))->toBe(100.10);
    });
});
