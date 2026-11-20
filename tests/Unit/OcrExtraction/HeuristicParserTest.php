<?php

declare(strict_types=1);

use Modules\OcrExtraction\Infrastructure\Providers\HeuristicInvoiceTextParser;

test('plain OCR text of a Swiss invoice is read into the payload with the three VAT rates', function (): void {
    $text = <<<'TEXT'
Muster Haustechnik AG
Musterstrasse 1, 8000 Zürich
UID: CHE-123.456.789 MWST
Rechnung Nr: R-2026-17
Datum: 05.03.2026
Zahlbar bis: 04.04.2026
Bestellung PO-2026-77
Montage Heizung 10 120.00 1'200.00
Hotelzimmer 2 100.00 200.00
Zutaten 1 50.00 50.00
8.1 % 1'200.00 97.20
2.6 % 50.00 1.30
3.8 % 200.00 7.60
Nettobetrag CHF 1'450.00
Total CHF 1'556.10
Referenz 21 00000 00003 13947 14300 09017
IBAN CH93 0076 2011 6238 5295 7
TEXT;

    $payload = (new HeuristicInvoiceTextParser)->parse($text);

    expect($payload->vendor['name'])->toBe('Muster Haustechnik AG')
        ->and($payload->vendor['uid'])->toBe('CHE-123.456.789')
        ->and($payload->vendor['iban'])->toBe('CH9300762011623852957')
        ->and($payload->document['number'])->toBe('R-2026-17')
        ->and($payload->document['issue_date'])->toBe('2026-03-05')
        ->and($payload->document['due_date'])->toBe('2026-04-04')
        ->and($payload->document['currency'])->toBe('CHF')
        ->and($payload->document['qr_reference'])->toBe('21 00000 00003 13947 14300 09017')
        ->and($payload->totals['gross'])->toBe(1556.1)
        ->and($payload->totals['net'])->toBe(1450.0)
        ->and(array_column($payload->vatBreakdown, 'rate'))->toBe([8.1, 2.6, 3.8])
        ->and($payload->vatBreakdown[0])->toBe(['rate' => 8.1, 'net' => 1200.0, 'vat' => 97.2])
        ->and($payload->lines)->toHaveCount(3)
        ->and($payload->lines[0]['description'])->toBe('Montage Heizung')
        ->and($payload->lines[0]['unit_price'])->toBe(120.0)
        ->and($payload->orderReferences)->toBe(['PO-2026-77'])
        ->and($payload->overallConfidence())->toBeLessThan(90.0)
        ->and($payload->overallConfidence())->toBeGreaterThan(50.0);
});

test('text with nothing recognisable yields an empty payload with no confidence', function (): void {
    $payload = (new HeuristicInvoiceTextParser)->parse("...\n!!!");

    expect($payload->lines)->toBe([])
        ->and($payload->totals['gross'])->toBeNull()
        ->and($payload->overallConfidence())->toBe(0.0);
});
