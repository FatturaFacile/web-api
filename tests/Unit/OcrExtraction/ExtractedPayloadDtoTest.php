<?php

declare(strict_types=1);

use Modules\OcrExtraction\Domain\ValueObjects\BoundingBoxDto;
use Modules\OcrExtraction\Domain\ValueObjects\ExtractedPayloadDto;

test('decimals are read in the formats of Swiss, Italian and German documents', function (): void {
    expect(ExtractedPayloadDto::toFloat("1'234.50"))->toBe(1234.5)
        ->and(ExtractedPayloadDto::toFloat('1.234,50'))->toBe(1234.5)
        ->and(ExtractedPayloadDto::toFloat('1,234.50'))->toBe(1234.5)
        ->and(ExtractedPayloadDto::toFloat('CHF 81.00'))->toBe(81.0)
        ->and(ExtractedPayloadDto::toFloat('12,5'))->toBe(12.5)
        ->and(ExtractedPayloadDto::toFloat('-3.20'))->toBe(-3.2)
        ->and(ExtractedPayloadDto::toFloat(7))->toBe(7.0)
        ->and(ExtractedPayloadDto::toFloat('n/a'))->toBeNull()
        ->and(ExtractedPayloadDto::toFloat(null))->toBeNull();
});

test('dates are normalized to ISO and impossible dates are refused', function (): void {
    expect(ExtractedPayloadDto::toDate('31.12.2026'))->toBe('2026-12-31')
        ->and(ExtractedPayloadDto::toDate('31/12/2026'))->toBe('2026-12-31')
        ->and(ExtractedPayloadDto::toDate('20261231'))->toBe('2026-12-31')
        ->and(ExtractedPayloadDto::toDate('2026-12-31'))->toBe('2026-12-31')
        ->and(ExtractedPayloadDto::toDate('31.02.2026'))->toBeNull()
        ->and(ExtractedPayloadDto::toDate('tomorrow'))->toBeNull()
        ->and(ExtractedPayloadDto::toDate(''))->toBeNull();
});

test('a loose engine answer becomes the normalized payload', function (): void {
    $payload = ExtractedPayloadDto::fromArray([
        'vendor' => ['name' => ' Muster AG ', 'uid' => 'che123456789', 'vat_number' => 'IT 012.345.678.90', 'iban' => 'CH93 0076 2011 6238 5295 7', 'extra' => 'ignored'],
        'document' => ['number' => 'R-2026-17', 'issue_date' => '05.03.2026', 'due_date' => '04.04.2026', 'currency' => 'chf'],
        'order_references' => ['PO-77', ' PO-77 ', ''],
        'totals' => ['net' => "1'000.00", 'vat' => '81.00', 'gross' => "1'081.00"],
        'vat_breakdown' => [['rate' => '8.1', 'net' => "1'000.00", 'vat' => '81.00'], ['rate' => null]],
        'lines' => [['description' => 'Consulting', 'quantity' => '2', 'unit_price' => '500', 'net_amount' => '1000', 'vat_rate' => '8.1'], ['description' => '  ']],
        'field_confidence' => ['totals.gross' => 140, 'vendor.name' => 'x', 'header.document_number' => 92.456],
    ]);

    expect($payload->vendor['name'])->toBe('Muster AG')
        ->and($payload->vendor['uid'])->toBe('CHE-123.456.789')
        ->and($payload->vendor['vat_number'])->toBe('IT01234567890')
        ->and($payload->vendor['iban'])->toBe('CH9300762011623852957')
        ->and($payload->vendor['qr_iban'])->toBeNull()
        ->and($payload->document['issue_date'])->toBe('2026-03-05')
        ->and($payload->document['currency'])->toBe('CHF')
        ->and($payload->orderReferences)->toBe(['PO-77'])
        ->and($payload->totals)->toBe(['net' => 1000.0, 'vat' => 81.0, 'gross' => 1081.0])
        ->and($payload->vatBreakdown)->toHaveCount(1)
        ->and($payload->lines)->toHaveCount(1)
        ->and($payload->lines[0]['net_amount'])->toBe(1000.0)
        ->and($payload->fieldConfidence)->toBe(['totals.gross' => 100.0, 'header.document_number' => 92.46])
        ->and($payload->overallConfidence())->toBe(96.23);
});

test('structured fields override the OCR reading and get full confidence', function (): void {
    $ocr = ExtractedPayloadDto::fromArray([
        'vendor' => ['name' => 'Muster AG', 'iban' => 'CH0000000000000000000'],
        'document' => ['number' => 'R-1', 'currency' => 'CHF', 'qr_reference' => '000000000000000000000000001'],
        'totals' => ['gross' => 100.0],
        'field_confidence' => ['vendor.iban' => 55, 'totals.gross' => 60, 'header.document_number' => 80],
    ]);
    $qr = ExtractedPayloadDto::fromArray([
        'vendor' => ['iban' => 'CH9300762011623852957'],
        'document' => ['qr_reference' => '210000000003139471430009017'],
        'totals' => ['gross' => 1081.0],
    ]);

    $merged = $ocr->overlay($qr, ['vendor.iban', 'header.qr_reference', 'totals.gross']);

    expect($merged->vendor['iban'])->toBe('CH9300762011623852957')
        ->and($merged->document['qr_reference'])->toBe('210000000003139471430009017')
        ->and($merged->document['number'])->toBe('R-1')
        ->and($merged->totals['gross'])->toBe(1081.0)
        ->and($merged->fieldConfidence['vendor.iban'])->toBe(100.0)
        ->and($merged->fieldConfidence['totals.gross'])->toBe(100.0)
        ->and($merged->fieldConfidence['header.qr_reference'])->toBe(100.0)
        ->and($merged->fieldConfidence['header.document_number'])->toBe(80.0);
});

test('the JSON schema asks for every section and the boxes', function (): void {
    $schema = ExtractedPayloadDto::jsonSchema();

    expect($schema['required'])->toBe(['vendor', 'document', 'totals', 'vat_breakdown', 'lines', 'field_confidence'])
        ->and(array_keys($schema['properties']))->toContain('bounding_boxes', 'order_references')
        ->and($schema['properties']['totals']['required'])->toBe(['net', 'vat', 'gross']);
});

test('a bounding box is clamped to the page and an empty region is refused', function (): void {
    $box = BoundingBoxDto::tryFromArray(['page' => 2, 'x' => 0.9, 'y' => -0.1, 'width' => 0.5, 'height' => 0.2]);

    expect($box?->toArray())->toBe(['page' => 2, 'x' => 0.9, 'y' => 0.0, 'width' => 0.1, 'height' => 0.2])
        ->and(BoundingBoxDto::tryFromArray(['x' => 0.1, 'y' => 0.1, 'width' => 0, 'height' => 0.1]))->toBeNull()
        ->and(BoundingBoxDto::tryFromArray(['x' => 'a', 'y' => 0.1, 'width' => 0.1, 'height' => 0.1]))->toBeNull()
        ->and(BoundingBoxDto::tryFromArray(['x' => 0.1, 'y' => 0.1, 'width' => 0.1, 'height' => 0.1])?->page)->toBe(1);
});
