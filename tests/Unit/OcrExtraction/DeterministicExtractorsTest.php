<?php

declare(strict_types=1);

use App\Services\ItalianSdi\FatturaPaParser;
use Modules\OcrExtraction\Infrastructure\Extraction\FatturaPaExtractor;
use Modules\OcrExtraction\Infrastructure\Extraction\ZugferdExtractor;

require_once __DIR__.'/OcrFixtures.php';

test('a native FatturaPA XML maps every field with full confidence and no OCR', function (): void {
    $extractor = new FatturaPaExtractor(new FatturaPaParser);
    $result = $extractor->extract(ocrFatturaPaXml());

    expect($extractor->looksLikeFatturaPa(ocrFatturaPaXml()))->toBeTrue()
        ->and($result)->not->toBeNull()
        ->and($result['document_type'])->toBe('TD01');

    $payload = $result['payload'];

    expect($payload->vendor)->toMatchArray(['name' => 'Rossi Srl', 'vat_number' => 'IT01234567890', 'fiscal_code' => 'RSSMRA80A01H501U', 'iban' => 'IT60X0542811101000000123456'])
        ->and($payload->document)->toMatchArray(['number' => 'FT-17', 'issue_date' => '2026-03-05', 'due_date' => '2026-04-04', 'currency' => 'EUR'])
        ->and($payload->orderReferences)->toBe(['PO-2026-77'])
        ->and($payload->totals)->toBe(['net' => 150.0, 'vat' => 33.0, 'gross' => 183.0])
        ->and($payload->vatBreakdown)->toBe([['rate' => 22.0, 'net' => 150.0, 'vat' => 33.0]])
        ->and($payload->lines)->toHaveCount(2)
        ->and($payload->lines[0])->toBe(['description' => 'Toner nero HP', 'quantity' => 2.0, 'unit_price' => 50.0, 'net_amount' => 100.0, 'vat_rate' => 22.0])
        ->and($payload->overallConfidence())->toBe(100.0)
        ->and($payload->fieldConfidence['lines[1].unit_price'])->toBe(100.0)
        ->and($payload->fieldConfidence['header.document_number'])->toBe(100.0);
});

test('an XML that is not a FatturaPA is not mapped', function (): void {
    $extractor = new FatturaPaExtractor(new FatturaPaParser);

    expect($extractor->extract('<invoice><number>1</number></invoice>'))->toBeNull()
        ->and($extractor->looksLikeFatturaPa('<invoice/>'))->toBeFalse();
});

test('the CII XML attached to a PDF is found and mapped with full confidence', function (): void {
    $extractor = new ZugferdExtractor;

    foreach ([true, false] as $compressed) {
        $xml = $extractor->embeddedXml(ocrPdfWithAttachment(ocrCiiXml(), $compressed));

        expect($xml)->toContain('CrossIndustryInvoice');

        $payload = $extractor->extract((string) $xml);

        expect($payload)->not->toBeNull()
            ->and($payload->vendor)->toMatchArray(['name' => 'Muster GmbH', 'uid' => 'CHE-123.456.789', 'vat_number' => 'DE123456789', 'iban' => 'DE89370400440532013000'])
            ->and($payload->document)->toMatchArray(['number' => 'RE-2026-0042', 'issue_date' => '2026-03-05', 'due_date' => '2026-04-04', 'currency' => 'EUR'])
            ->and($payload->orderReferences)->toBe(['PO-2026-77'])
            ->and($payload->totals)->toBe(['net' => 1000.0, 'vat' => 190.0, 'gross' => 1190.0])
            ->and($payload->vatBreakdown)->toBe([['rate' => 19.0, 'net' => 1000.0, 'vat' => 190.0]])
            ->and($payload->lines[0])->toBe(['description' => 'Wartungsvertrag', 'quantity' => 2.0, 'unit_price' => 500.0, 'net_amount' => 1000.0, 'vat_rate' => 19.0])
            ->and($payload->overallConfidence())->toBe(100.0);
    }
});

test('a PDF without an attachment, or with an unrelated one, yields no XML', function (): void {
    $extractor = new ZugferdExtractor;

    expect($extractor->embeddedXml("%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF"))->toBeNull()
        ->and($extractor->embeddedXml(ocrPdfWithAttachment('<other>1</other>')))->toBeNull()
        ->and($extractor->embeddedXml('not a pdf'))->toBeNull()
        ->and($extractor->extract('<Invoice/>'))->toBeNull();
});

test('XML with a document type declaration is refused', function (): void {
    $xml = '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY a "b">]><rsm:CrossIndustryInvoice xmlns:rsm="urn:x"/>';

    expect((new ZugferdExtractor)->extract($xml))->toBeNull();
});
