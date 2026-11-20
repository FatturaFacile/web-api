<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\SDI\Enums\FpaStatus;
use Modules\SDI\Enums\SdiNotificationType;
use Modules\SDI\Models\FpaDocument;
use Modules\SDI\Models\SdiConfiguration;
use Modules\SDI\Models\SdiNotification;
use Modules\SDI\Services\DigitalSignatureService;
use Modules\SDI\Services\P7mExtractor;
use Modules\SDI\Services\ProgressivoInvioGenerator;
use Modules\SDI\Services\RejectedDocumentResubmissionService;
use Modules\SDI\Services\SdiFileNamer;
use Modules\SDI\Services\SdiNotificationProcessorService;
use Modules\SDI\Services\SogeiCongruityValidator;
use Modules\SDI\Signature\LocalPkcs12SignatureProvider;

uses(RefreshDatabase::class);

function fpaTenant(): Tenant
{
    return Tenant::create(['name' => 'Rossi Srl', 'legal_name' => 'Rossi Srl', 'vat_number' => '00811720580', 'fiscal_code' => '00811720580', 'country' => 'IT', 'default_currency' => 'EUR']);
}

function fpaXml(array $o = []): string
{
    $o += ['rate' => '22.00', 'nature' => '', 'taxable' => '100.00', 'tax' => '22.00', 'total' => '100.00', 'code' => 'ABC1234', 'format' => 'FPR12', 'type' => 'TD01', 'vat' => '00811720580', 'extra' => ''];
    $nature = $o['nature'] !== '' ? "<Natura>{$o['nature']}</Natura>" : '';

    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<p:FatturaElettronica xmlns:p="http://ivaservizi.agenziaentrate.gov.it/docs/xsd/fatture/v1.2" versione="{$o['format']}">
<FatturaElettronicaHeader>
<DatiTrasmissione><IdTrasmittente><IdPaese>IT</IdPaese><IdCodice>00811720580</IdCodice></IdTrasmittente><ProgressivoInvio>00001</ProgressivoInvio><FormatoTrasmissione>{$o['format']}</FormatoTrasmissione><CodiceDestinatario>{$o['code']}</CodiceDestinatario></DatiTrasmissione>
<CedentePrestatore><DatiAnagrafici><IdFiscaleIVA><IdPaese>IT</IdPaese><IdCodice>{$o['vat']}</IdCodice></IdFiscaleIVA></DatiAnagrafici></CedentePrestatore>
<CessionarioCommittente><DatiAnagrafici><IdFiscaleIVA><IdPaese>IT</IdPaese><IdCodice>01234567897</IdCodice></IdFiscaleIVA></DatiAnagrafici></CessionarioCommittente>
{$o['extra']}
</FatturaElettronicaHeader>
<FatturaElettronicaBody>
<DatiGenerali><DatiGeneraliDocumento><TipoDocumento>{$o['type']}</TipoDocumento><Divisa>EUR</Divisa><Data>2026-03-15</Data><Numero>7</Numero></DatiGeneraliDocumento></DatiGenerali>
<DatiBeniServizi>
<DettaglioLinee><NumeroLinea>1</NumeroLinea><Descrizione>x</Descrizione><Quantita>1.00</Quantita><PrezzoUnitario>100.00</PrezzoUnitario><PrezzoTotale>{$o['total']}</PrezzoTotale><AliquotaIVA>{$o['rate']}</AliquotaIVA>{$nature}</DettaglioLinee>
<DatiRiepilogo><AliquotaIVA>{$o['rate']}</AliquotaIVA>{$nature}<ImponibileImporto>{$o['taxable']}</ImponibileImporto><Imposta>{$o['tax']}</Imposta><EsigibilitaIVA>I</EsigibilitaIVA></DatiRiepilogo>
</DatiBeniServizi>
</FatturaElettronicaBody>
</p:FatturaElettronica>
XML;
}

function fpaCodes($result): array
{
    return array_column($result->errors, 'code');
}

test('a congruent file passes the sogei rules', function (): void {
    expect(fpaCodes(app(SogeiCongruityValidator::class)->validate(fpaXml())))->toBe([]);
});

test('sogei rules catch totals, tax, nature and recipient errors', function (): void {
    $v = app(SogeiCongruityValidator::class);

    expect(fpaCodes($v->validate(fpaXml(['total' => '90.00']))))->toContain('00423')
        ->and(fpaCodes($v->validate(fpaXml(['taxable' => '95.00']))))->toContain('00422')
        ->and(fpaCodes($v->validate(fpaXml(['tax' => '20.00']))))->toContain('00424')
        ->and(fpaCodes($v->validate(fpaXml(['rate' => '0.00', 'tax' => '0.00']))))->toContain('00400')
        ->and(fpaCodes($v->validate(fpaXml(['nature' => 'N4']))))->toContain('00401')
        ->and(fpaCodes($v->validate(fpaXml(['vat' => '00811720581']))))->toContain('00301')
        ->and(fpaCodes($v->validate(fpaXml(['code' => 'ABC']))))->toContain('00311')
        ->and(fpaCodes($v->validate(fpaXml(['format' => 'FPA12', 'code' => 'ABC1234']))))->toContain('00311');
});

test('a delivered invoice with the same number and date is a duplicate', function (): void {
    $tenant = fpaTenant();
    FpaDocument::factory()->create(['tenant_id' => $tenant->id, 'document_number' => '7', 'document_date' => '2026-03-15', 'status' => FpaStatus::Delivered]);

    expect(fpaCodes(app(SogeiCongruityValidator::class)->validate(fpaXml(), $tenant->id)))->toContain('00404');
});

test('progressivo invio is base 36 and never repeats', function (): void {
    $tenant = fpaTenant();
    $generator = app(ProgressivoInvioGenerator::class);

    expect($generator->next($tenant)['progressive'])->toBe('00001')
        ->and($generator->next($tenant)['file_name'])->toBe('IT00811720580_00002.xml')
        ->and($generator->encode(60466175))->toBe('ZZZZZ');
});

test('first transmission and resubmission share one progressive sequence', function (): void {
    $tenant = fpaTenant();

    $first = app(SdiFileNamer::class)->next($tenant);
    $second = app(ProgressivoInvioGenerator::class)->next($tenant);
    $third = app(SdiFileNamer::class)->next($tenant);

    expect([$first['progressive'], $second['progressive'], $third['progressive']])->toBe(['00001', '00002', '00003']);
});

test('a rejected document opens a five day window and a linked resubmission', function (): void {
    Storage::fake('local');
    $this->travelTo('2026-03-15 10:00:00');
    $document = FpaDocument::factory()->create(['tenant_id' => fpaTenant()->id, 'file_name' => 'IT1_00001.xml']);
    $ns = '<NotificaScarto><IdentificativoSdI>1</IdentificativoSdI><MessageId>m1</MessageId><ListaErrori><Errore><Codice>00404</Codice><Descrizione>Duplicate</Descrizione></Errore></ListaErrori></NotificaScarto>';

    $processor = app(SdiNotificationProcessorService::class);
    $processor->process($document, SdiNotificationType::NS, $ns);
    $processor->process($document, SdiNotificationType::NS, $ns);
    $document->refresh();

    expect($document->status)->toBe(FpaStatus::Rejected)
        ->and($document->resubmission_deadline_at->toDateString())->toBe('2026-03-20')
        ->and(SdiNotification::withoutGlobalScopes()->where('fpa_document_id', $document->id)->count())->toBe(1)
        ->and($document->validation_errors[0]['code'])->toBe('00404');

    $revision = app(RejectedDocumentResubmissionService::class)->createRevision($document);
    expect($revision->parent_fpa_document_id)->toBe($document->id)->and($revision->document_number)->toBe($document->document_number);

    $this->travelTo('2026-03-21 10:00:00');
    app(RejectedDocumentResubmissionService::class)->createRevision($document);
})->throws(DomainException::class, 'deadline');

test('receipts drive the lifecycle', function (): void {
    Storage::fake('local');
    $processor = app(SdiNotificationProcessorService::class);
    $tenant = fpaTenant();
    $rc = fn (FpaDocument $d) => $processor->process($d, SdiNotificationType::RC, '<RicevutaConsegna><IdentificativoSdI>1</IdentificativoSdI><MessageId>r</MessageId></RicevutaConsegna>');

    $a = FpaDocument::factory()->create(['tenant_id' => $tenant->id]);
    $rc($a);
    $processor->process($a, SdiNotificationType::MC, '<NotificaMancataConsegna><MessageId>x</MessageId></NotificaMancataConsegna>');
    expect($a->refresh()->status)->toBe(FpaStatus::Delivered);

    $b = FpaDocument::factory()->create(['tenant_id' => $tenant->id]);
    $processor->process($b, SdiNotificationType::MC, '<NotificaMancataConsegna><MessageId>y</MessageId></NotificaMancataConsegna>');
    expect($b->refresh()->status)->toBe(FpaStatus::Undelivered);

    $c = FpaDocument::factory()->create(['tenant_id' => $tenant->id, 'format_transmission' => 'FPA12']);
    $rc($c);
    $processor->process($c, SdiNotificationType::NE, '<NotificaEsito><Esito>EC02</Esito></NotificaEsito>');
    expect($c->refresh()->status)->toBe(FpaStatus::PaRejected);

    $d = FpaDocument::factory()->create(['tenant_id' => $tenant->id, 'format_transmission' => 'FPA12']);
    $processor->process($d, SdiNotificationType::NE, '<NotificaEsito><Esito>EC01</Esito></NotificaEsito>');
    expect($d->refresh()->status)->toBe(FpaStatus::PaAccepted);

    $e = FpaDocument::factory()->create(['tenant_id' => $tenant->id]);
    $processor->process($e, SdiNotificationType::AT, '<AttestazioneTrasmissioneFattura/>');
    expect($e->refresh()->status)->toBe(FpaStatus::TransmittedAttested);

    $f = FpaDocument::factory()->create(['tenant_id' => $tenant->id, 'format_transmission' => 'FPA12', 'transmitted_at' => now()->subDays(16)]);
    expect($processor->expirePaDeadlines())->toBe(1)->and($f->refresh()->status)->toBe(FpaStatus::DeadlinePassed);
});

test('a CAdES signature round trips through the p7m extractor and XAdES embeds a signature', function (): void {
    ['pkcs12' => $p12, 'password' => $password] = LocalPkcs12SignatureProvider::generateSelfSigned();
    $configuration = new SdiConfiguration(['signature_mode' => 'local_pkcs12']);
    $configuration->encrypted_certificate = json_encode(['pkcs12' => base64_encode($p12), 'password' => $password]);
    $xml = fpaXml();

    $service = app(DigitalSignatureService::class);
    $cades = $service->sign($configuration, $xml, 'IT1_00001.xml');

    expect($cades->fileName)->toBe('IT1_00001.xml.p7m')
        ->and(app(P7mExtractor::class)->extract($cades->content))->toBe($xml);

    $xades = $service->sign($configuration, $xml, 'IT1_00001.xml', 'xades');
    $dom = new DOMDocument;
    $dom->loadXML($xades->content);

    expect($dom->getElementsByTagNameNS('http://www.w3.org/2000/09/xmldsig#', 'SignatureValue')->length)->toBe(1)
        ->and($dom->getElementsByTagNameNS('http://uri.etsi.org/01903/v1.3.2#', 'QualifyingProperties')->length)->toBe(1);
});
