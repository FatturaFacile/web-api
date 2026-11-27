<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Modules\Esterometro\Domain\Enums\SelfInvoiceDocumentType;
use Modules\Esterometro\Domain\Enums\TransactionNature;
use Modules\Esterometro\Models\SelfInvoice;
use Modules\Esterometro\Models\SelfInvoiceLine;
use Modules\Esterometro\Services\SelfInvoiceDeadlineMonitorService;
use Modules\Esterometro\Services\SelfInvoiceFatturaPaXmlBuilder;

it('builds role-inverted FatturaPA XML with supplier as CedentePrestatore and tenant as CessionarioCommittente', function (): void {
    $tenant = new Tenant([
        'name' => 'FatturaFacile Italia Srl',
        'company_name' => 'FatturaFacile Italia Srl',
        'vat_number' => 'IT12345678901',
        'fiscal_code' => '12345678901',
        'address' => 'Via Torino 10',
        'postal_code' => '20123',
        'city' => 'Milano',
        'province' => 'MI',
    ]);

    $selfInvoice = new SelfInvoice([
        'document_type' => SelfInvoiceDocumentType::TD17,
        'document_number' => 'AF-2026-00001',
        'fiscal_year' => 2026,
        'sequential_number' => 1,
        'integration_date' => Carbon::parse('2026-04-10'),
        'transmission_deadline_date' => Carbon::parse('2026-05-15'),
        'supplier_country_code' => 'DE',
        'supplier_region' => 'EU',
        'supplier_name' => 'CloudHosting GmbH',
        'supplier_vat_number' => 'DE998877665',
        'supplier_address' => ['street' => 'Alexanderplatz 1', 'city' => 'Berlin', 'postal_code' => '10178'],
        'original_invoice_number' => 'INV-DE-42',
        'original_invoice_date' => Carbon::parse('2026-04-05'),
        'taxable_amount_eur' => '1000.00',
        'vat_amount_eur' => '220.00',
        'gross_amount_eur' => '1220.00',
        'soggetto_emittente' => 'CC',
        'recipient_code' => '0000000',
        'sdi_progressive' => '00001',
    ]);

    $line = new SelfInvoiceLine([
        'line_number' => 1,
        'description' => 'Cloud infrastructure services April 2026',
        'transaction_nature' => TransactionNature::Services,
        'quantity' => '1.0000',
        'unit_price_eur' => '1000.0000',
        'taxable_amount_eur' => '1000.00',
        'vat_rate' => '22.00',
        'vat_amount_eur' => '220.00',
    ]);
    $selfInvoice->setRelation('lines', collect([$line]));

    $xml = (new SelfInvoiceFatturaPaXmlBuilder)->build($selfInvoice, $tenant);

    $dom = new DOMDocument;
    expect($dom->loadXML($xml))->toBeTrue();

    $xpath = new DOMXPath($dom);
    expect($xpath->evaluate('string(//*[local-name()="TipoDocumento"])'))->toBe('TD17')
        ->and($xpath->evaluate('string(//*[local-name()="SoggettoEmittente"])'))->toBe('CC')
        ->and($xpath->evaluate('string(//*[local-name()="CodiceDestinatario"])'))->toBe('0000000')
        ->and($xpath->evaluate('string(//*[local-name()="CedentePrestatore"]//*[local-name()="IdPaese"])'))->toBe('DE')
        ->and($xpath->evaluate('string(//*[local-name()="CedentePrestatore"]//*[local-name()="IdCodice"])'))->toBe('DE998877665')
        ->and($xpath->evaluate('string(//*[local-name()="CessionarioCommittente"]//*[local-name()="IdPaese"])'))->toBe('IT')
        ->and($xpath->evaluate('string(//*[local-name()="CessionarioCommittente"]//*[local-name()="IdCodice"])'))->toBe('12345678901')
        ->and($xpath->evaluate('string(//*[local-name()="DatiFattureCollegate"]//*[local-name()="IdDocumento"])'))->toBe('INV-DE-42');
});

it('computes 15th of following month statutory deadline and TD20 regularization deadlines', function (): void {
    $monitor = new SelfInvoiceDeadlineMonitorService;

    $td17Deadline = $monitor->computeTransmissionDeadline(
        SelfInvoiceDocumentType::TD17,
        Carbon::parse('2026-04-28'),
        Carbon::parse('2026-04-20'),
        Carbon::parse('2026-04-22'),
    );
    expect($td17Deadline->toDateString())->toBe('2026-05-15');

    $td20Omitted = $monitor->computeTransmissionDeadline(
        SelfInvoiceDocumentType::TD20,
        Carbon::parse('2026-05-01'),
        Carbon::parse('2026-01-10'),
        null,
        'omitted_4_months',
    );
    expect($td20Omitted->toDateString())->toBe('2026-05-10');
});
