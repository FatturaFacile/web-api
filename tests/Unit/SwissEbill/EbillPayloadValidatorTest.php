<?php

declare(strict_types=1);

use Modules\SwissEbill\Domain\Exceptions\EbillValidationException;
use Modules\SwissEbill\Infrastructure\Xml\EbillPayloadValidator;

/**
 * A minimal schema-valid payload; the callable edits the XML text to break one rule.
 */
function ebillSampleXml(?callable $mutate = null): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8"?><EbillInvoice xmlns="urn:swiss-ebill:invoice:1" version="SIX eBill XML">'
        .'<Header><TransmissionId>t-1</TransmissionId><BillerID>41010560425610172</BillerID><RecipientID>41000000000000001</RecipientID><Channel>B2B</Channel><CreatedAt>2026-03-05T10:00:00Z</CreatedAt></Header>'
        .'<Biller><Name>Muster AG</Name><UID>CHE-100.000.012</UID><Address><Street>Hauptstrasse 5</Street><PostalCode>8000</PostalCode><City>Zürich</City><Country>CH</Country></Address>'
        .'<PaymentAccount><IBAN>CH4431999123000889012</IBAN><Reference type="QRR">210000000003139471430009017</Reference></PaymentAccount></Biller>'
        .'<Recipient><Name>Kunde AG</Name><Address><PostalCode>8001</PostalCode><City>Zürich</City><Country>CH</Country></Address></Recipient>'
        .'<Invoice><Number>INV-1</Number><IssueDate>2026-03-01</IssueDate><DueDate>2026-03-31</DueDate><Currency>CHF</Currency>'
        .'<Lines><Line pos="1"><Description>Consulting</Description><Quantity>1.0000</Quantity><UnitPrice>100.0000</UnitPrice><DiscountPercent>0.00</DiscountPercent><VatRate>8.10</VatRate><NetAmount>100.00</NetAmount></Line></Lines>'
        .'<VatSummary><Vat rate="8.10"><TaxableAmount>100.00</TaxableAmount><TaxAmount>8.10</TaxAmount></Vat></VatSummary>'
        .'<Totals><Net>100.00</Net><Tax>8.10</Tax><Gross>108.10</Gross></Totals></Invoice>'
        .'<AdditionalInformation><EmbeddedDocument mimeType="application/pdf" filename="INV-1.pdf" sha256="'.str_repeat('a', 64).'" sizeBytes="3">QUJD</EmbeddedDocument></AdditionalInformation>'
        .'</EbillInvoice>';

    return $mutate === null ? $xml : $mutate($xml);
}

test('a complete payload passes the schema', function (): void {
    (new EbillPayloadValidator)->assertValid(ebillSampleXml());

    expect(true)->toBeTrue();
});

test('the courtesy PDF is optional', function (): void {
    (new EbillPayloadValidator)->assertValid(ebillSampleXml(fn (string $xml): string => (string) preg_replace('#<AdditionalInformation>.*</AdditionalInformation>#s', '', $xml)));

    expect(true)->toBeTrue();
});

test('a payload that breaks the schema is refused with the violations', function (string $label, callable $mutate): void {
    try {
        (new EbillPayloadValidator)->assertValid(ebillSampleXml($mutate));
    } catch (EbillValidationException $e) {
        expect($e->codes())->toContain('xsd_invalid')->and($e->errorCode())->toBe('payload_invalid');

        return;
    }

    throw new RuntimeException("{$label} should have been refused.");
})->with([
    'malformed biller id' => ['biller id', fn (string $xml): string => str_replace('41010560425610172', '4101', $xml)],
    'unsupported currency' => ['currency', fn (string $xml): string => str_replace('<Currency>CHF</Currency>', '<Currency>USD</Currency>', $xml)],
    'invalid UID' => ['uid', fn (string $xml): string => str_replace('CHE-100.000.012', 'CHE-1000', $xml)],
    'missing recipient city' => ['city', fn (string $xml): string => str_replace('<City>Zürich</City><Country>CH</Country></Address></Recipient>', '<Country>CH</Country></Address></Recipient>', $xml)],
    'wrong checksum shape' => ['sha256', fn (string $xml): string => str_replace(str_repeat('a', 64), 'zz', $xml)],
    'no lines' => ['lines', fn (string $xml): string => (string) preg_replace('#<Lines>.*</Lines>#s', '<Lines/>', $xml)],
]);

test('malformed XML is refused', function (): void {
    expect(fn () => (new EbillPayloadValidator)->assertValid('<EbillInvoice'))->toThrow(EbillValidationException::class);
});
