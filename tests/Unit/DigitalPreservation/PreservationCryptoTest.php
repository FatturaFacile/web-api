<?php

declare(strict_types=1);

use App\Contracts\Security\HostResolver;
use App\Services\Security\OutboundUrlGuard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Modules\DigitalPreservation\Domain\Enums\BatchStatus;
use Modules\DigitalPreservation\Domain\Enums\DocumentCategory;
use Modules\DigitalPreservation\Domain\Enums\JurisdictionMode;
use Modules\DigitalPreservation\Domain\Enums\PreservationProvider;
use Modules\DigitalPreservation\Domain\Exceptions\PreservationException;
use Modules\DigitalPreservation\Domain\ValueObjects\RetentionPolicy;
use Modules\DigitalPreservation\Domain\ValueObjects\Sha256Hash;
use Modules\DigitalPreservation\Infrastructure\Models\PreservationBatch;
use Modules\DigitalPreservation\Infrastructure\Models\PreservationBatchItem;
use Modules\DigitalPreservation\Infrastructure\Models\PreservationConfiguration;
use Modules\DigitalPreservation\Infrastructure\Pdfa\SimplePdfWriter;
use Modules\DigitalPreservation\Infrastructure\Services\AgidPdvXmlBuilder;
use Modules\DigitalPreservation\Infrastructure\Services\MerkleTreeHasher;
use Modules\DigitalPreservation\Infrastructure\Services\PdfAConformanceService;
use Modules\DigitalPreservation\Infrastructure\Services\Rfc3161TimestampService;
use Tests\Support\StaticHostResolver;

function pdvBatch(): PreservationBatch
{
    $batch = new PreservationBatch([
        'batch_number' => '2026-000042', 'jurisdiction' => JurisdictionMode::Dual, 'status' => BatchStatus::Draft, 'document_category' => DocumentCategory::ActiveInvoices,
        'period_from' => '2026-11-01', 'period_to' => '2026-11-30', 'retention_until' => '2036-11-30',
        'merkle_root_sha256' => str_repeat('e', 64), 'previous_batch_sha256' => str_repeat('8', 64),
    ]);
    $batch->tenant_id = '9d8f4a2e-3b1c-4f8a-9c2d-1e5f7a8b9c0d';

    return $batch;
}

/**
 * @return Collection<int, PreservationBatchItem>
 */
function pdvItems(): Collection
{
    $base = ['original_filename' => 'a.xml', 'archived_filename' => 'a.xml', 'archive_path' => 'documents/a.xml', 'mime_type' => 'application/xml', 'file_size_bytes' => 3, 'preservable_type' => 'invoice', 'preservable_id' => '1', 'sequence' => 1, 'document_type' => 'x'];

    return collect([
        new PreservationBatchItem($base + [
            'document_uuid' => 'u1', 'sha256_hash' => hash('sha256', 'invoice'), 'document_number' => 'FT-2026/00125', 'document_date' => '2026-11-15', 'pdfa_conformance_level' => 'XML',
            'metadata_json' => ['agid_type' => 'FATTURA_ELETTRONICA_ATTIVA', 'id_doc' => 'DOC-INV-2026-00125', 'subject' => 'Fattura n. 125', 'standard' => 'FatturaPA-v1.2.2', 'formation_date' => '2026-11-15T10:14:22+01:00',
                'sender' => ['name' => 'Acme S.r.l.', 'tax_id' => 'IT01234567890'], 'recipient' => ['name' => 'Cliente Beta', 'tax_id' => 'IT09876543210'],
                'fiscal' => ['sdi_identifier' => '9876543210', 'total_amount' => '1220.00', 'currency' => 'EUR'], 'signature' => ['signed' => true, 'format' => 'CAdES-BES', 'outcome' => 'VALID']],
        ]),
        new PreservationBatchItem($base + [
            'sequence' => 2, 'document_uuid' => 'u2', 'sha256_hash' => hash('sha256', 'rc'), 'archive_path' => 'documents/rc.xml',
            'metadata_json' => ['agid_type' => 'NOTIFICA_SDI_RC', 'id_doc' => 'DOC-SDI-RC-9876543210', 'related' => [['id_doc' => 'DOC-INV-2026-00125', 'relation' => 'RICEVUTA_DI']], 'formation_date' => '2026-11-15T10:16:05+01:00'],
        ]),
        new PreservationBatchItem($base + [
            'sequence' => 3, 'document_uuid' => 'u3', 'sha256_hash' => hash('sha256', 'scan'), 'archive_path' => 'documents/s.pdf', 'archived_filename' => 's.pdf', 'mime_type' => 'application/pdf',
            'pdfa_conformance_level' => 'PDF/A-2b', 'is_pdfa_compliant' => true,
            'metadata_json' => ['agid_type' => 'DOCUMENTO_ANALOGICO_DEMATERIALIZZATO', 'id_doc' => 'DOC-ANA-2026-00088', 'digital_native' => false, 'formation_date' => '2026-11-12T14:30:00+01:00'],
        ]),
    ]);
}

function pdvXml(): string
{
    $configuration = new PreservationConfiguration(['provider' => PreservationProvider::LocalWorm, 'preservation_officer_name' => 'Mario Rossi', 'preservation_officer_tax_code' => 'RSSMRA80A01F205X', 'default_retention_years' => 10]);

    return (new AgidPdvXmlBuilder)->build(pdvBatch(), pdvItems(), $configuration, ['name' => 'Acme S.r.l. / Acme AG', 'tax_code' => '01234567890', 'vat' => 'IT01234567890'], Carbon::parse('2026-11-19T22:00:00+01:00'));
}

test('the Merkle root is deterministic and any change of a leaf or of the order changes it', function (): void {
    $hasher = new MerkleTreeHasher;
    $leaves = array_map(fn (string $c): string => $hasher->leaf($c), ['a', 'b', 'c']);

    expect($hasher->root($leaves))->toBe($hasher->root($leaves))
        ->and($hasher->root([]))->toBe(hash('sha256', ''))
        ->and($hasher->root([$leaves[0]]))->toBe($leaves[0])
        ->and($hasher->root($leaves))->not->toBe($hasher->root(array_reverse($leaves)))
        ->and($hasher->verify($leaves, $hasher->root($leaves)))->toBeTrue()
        ->and($hasher->verify([$hasher->leaf('a'), $hasher->leaf('B'), $leaves[2]], $hasher->root($leaves)))->toBeFalse();

    $pair = hash('sha256', hex2bin($leaves[0]).hex2bin($leaves[1]), true);
    expect($hasher->root([$leaves[0], $leaves[1], $leaves[2]]))->toBe(hash('sha256', $pair.hash('sha256', hex2bin($leaves[2]).hex2bin($leaves[2]), true)));
});

test('the SHA-256 value object validates its input', function (): void {
    expect(Sha256Hash::ofContent('abc')->hex)->toBe(hash('sha256', 'abc'))
        ->and(Sha256Hash::fromHex(strtoupper(hash('sha256', 'abc')))->equals(hash('sha256', 'abc')))->toBeTrue()
        ->and(fn () => Sha256Hash::fromHex('xyz'))->toThrow(InvalidArgumentException::class);
});

test('the retention policy never goes below ten years', function (): void {
    $policy = new RetentionPolicy(3);

    expect($policy->years)->toBe(10)
        ->and($policy->lockUntil(Carbon::parse('2026-11-30'))->toDateString())->toBe('2036-11-30')
        ->and((new RetentionPolicy(15))->years)->toBe(15)
        ->and($policy->isLocked(Carbon::parse('2036-11-30'), Carbon::parse('2036-11-30')))->toBeTrue()
        ->and($policy->isLocked(Carbon::parse('2036-11-30'), Carbon::parse('2036-12-01')))->toBeFalse();
});

test('the PdV.xml carries the mandatory AgID tags, the namespace and the SDI cross reference, and is deterministic', function (): void {
    $xml = pdvXml();
    $dom = new DOMDocument;
    $dom->loadXML($xml);
    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('p', AgidPdvXmlBuilder::Namespace);

    expect($dom->encoding)->toBe('UTF-8')
        ->and($dom->documentElement->namespaceURI)->toBe('urn:agid:conservazione:pdv:v1.2')
        ->and($dom->documentElement->getAttribute('IdPdV'))->toBe('PDV-2026-000042')
        ->and($dom->documentElement->getAttribute('Giurisdizione'))->toBe('DUAL')
        ->and($dom->documentElement->getAttribute('Versione'))->toBe('1.2')
        ->and($xpath->evaluate('string(//p:RadiceMerkleBatch)'))->toBe(str_repeat('e', 64))
        ->and($xpath->evaluate('string(//p:ImprontaBatchPrecedente)'))->toBe(str_repeat('8', 64))
        ->and($xpath->evaluate('string(//p:DataScadenzaVincolo)'))->toBe('2036-11-30')
        ->and($xpath->evaluate('string(//p:ResponsabileConservazione/p:CodiceFiscale)'))->toBe('RSSMRA80A01F205X')
        ->and($xpath->evaluate('string(//p:ConservatoreDestinazione)'))->toBe('LOCAL_WORM')
        ->and($xpath->evaluate('string(//p:IndiceDocumenti/@TotaleDocumenti)'))->toBe('3')
        ->and($xpath->evaluate('count(//p:Documento)'))->toBe(3.0)
        ->and($xpath->evaluate('string(//p:Documento[1]/@TipologiaDocumentale)'))->toBe('FATTURA_ELETTRONICA_ATTIVA')
        ->and($xpath->evaluate('string(//p:Documento[1]/p:ImprontaCrittografica/@Algoritmo)'))->toBe('SHA-256')
        ->and($xpath->evaluate('string(//p:Documento[1]/p:MetadatiFiscali/p:ImportoTotale)'))->toBe('1220.00')
        ->and($xpath->evaluate('string(//p:Documento[1]/p:VerificaFirmaDigitale/p:EsitoVerifica)'))->toBe('VALID')
        ->and($xpath->evaluate('string(//p:Documento[2]/p:DocumentiCorrelati/p:RiferimentoIdDoc)'))->toBe('DOC-INV-2026-00125')
        ->and($xpath->evaluate('string(//p:Documento[2]/p:DocumentiCorrelati/p:RiferimentoIdDoc/@TipoRelazione)'))->toBe('RICEVUTA_DI')
        ->and($xpath->evaluate('string(//p:Documento[3]/@Informatico)'))->toBe('false')
        ->and($xpath->evaluate('string(//p:Documento[3]/p:AttestazioneConformita/p:RiferimentoNormativo)'))->toBe('Art. 22 CAD / Art. 7 GeBüV')
        ->and($xpath->evaluate('string(//p:Documento[3]/p:Formato/p:StandardConformita)'))->toBe('PDF/A-2b')
        ->and($xml)->toBe(pdvXml());
});

test('the PdV.xml validator rejects a file that breaks the schema', function (): void {
    $builder = new AgidPdvXmlBuilder;
    $builder->assertValid(pdvXml());

    expect(fn () => $builder->assertValid(str_replace('SHA-256', 'MD5', pdvXml())))->toThrow(PreservationException::class)
        ->and(fn () => $builder->assertValid(preg_replace('/<IdDoc>.*?<\/IdDoc>/s', '', pdvXml())))->toThrow(PreservationException::class)
        ->and(fn () => $builder->assertValid('<not-xml'))->toThrow(PreservationException::class);
});

test('the local test time-stamp authority signs the imprint, is never qualified and detects tampering', function (): void {
    config(['digital_preservation.allow_local_tsa' => true]);
    $service = new Rfc3161TimestampService(app(OutboundUrlGuard::class));
    $hash = hash('sha256', 'pdv');

    $stamp = $service->stamp($hash, null);

    expect($stamp['provider'])->toBe('local-test-tsa')->and($stamp['qualified'])->toBeFalse()
        ->and($service->verify($stamp['token'], $hash))->toBeTrue()
        ->and($service->verify($stamp['token'], hash('sha256', 'other')))->toBeFalse()
        ->and($service->verify(str_replace('"gen_time":"', '"gen_time":"1999', $stamp['token']), $hash))->toBeFalse()
        ->and($service->verify('', $hash))->toBeFalse();

    config(['digital_preservation.allow_local_tsa' => false]);
    expect(fn () => $service->stamp($hash, null))->toThrow(PreservationException::class);
});

test('a remote time-stamp authority is only used over an allowed https address and its failure is mapped', function (): void {
    app()->instance(HostResolver::class, new StaticHostResolver);
    Http::preventStrayRequests();
    Http::fake(['tsa.example.org/*' => Http::response('nope', 503)]);
    $service = new Rfc3161TimestampService(app(OutboundUrlGuard::class));

    expect(fn () => $service->stamp(hash('sha256', 'x'), new PreservationConfiguration(['tsa_url' => 'https://tsa.example.org/tsr'])))->toThrow(PreservationException::class)
        ->and(fn () => $service->stamp(hash('sha256', 'x'), new PreservationConfiguration(['tsa_url' => 'http://tsa.example.org/tsr'])))->toThrow(PreservationException::class)
        ->and(fn () => $service->stamp(hash('sha256', 'x'), new PreservationConfiguration(['tsa_url' => 'https://127.0.0.1/tsr'])))->toThrow(PreservationException::class);

    expect($service->verify("\x30\x03abc".hex2bin(hash('sha256', 'x')), hash('sha256', 'x')))->toBeTrue()
        ->and($service->verify("\x30\x03abc", hash('sha256', 'x')))->toBeFalse();
});

test('PDF/A inspection needs the output intent and the XMP identification, and conversion produces a conformant file', function (): void {
    config(['digital_preservation.pdfa_driver' => 'simulated']);
    $service = new PdfAConformanceService;

    expect($service->inspect(SimplePdfWriter::build('t', ['x'], ['part' => 1, 'conformance' => 'B'])))->toBe('PDF/A-1b')
        ->and($service->inspect(SimplePdfWriter::build('t', ['x'], ['part' => 2, 'conformance' => 'B'])))->toBe('PDF/A-2b')
        ->and($service->inspect(SimplePdfWriter::build('t', ['x'])))->toBeNull()
        ->and($service->inspect('not a pdf'))->toBeNull()
        ->and($service->isArchival('<a/>', 'application/xml', 'f.xml'))->toBeTrue()
        ->and($service->isArchival('x', 'image/jpeg', 'f.jpg'))->toBeFalse();

    $kept = $service->ensureArchival('<a/>', 'application/xml', 'f.xml');
    expect($kept)->toMatchArray(['converted' => false, 'level' => 'XML', 'pdfa' => false]);

    $converted = $service->ensureArchival('jpeg-bytes', 'image/jpeg', 'scan.jpg', 'PDF/A-1b');
    expect($converted)->toMatchArray(['converted' => true, 'level' => 'PDF/A-1b', 'pdfa' => true, 'mime' => 'application/pdf', 'filename' => 'scan_pdfa.pdf'])
        ->and($service->inspect($converted['content']))->toBe('PDF/A-1b');

    $already = $service->ensureArchival(SimplePdfWriter::build('t', ['x'], ['part' => 2, 'conformance' => 'B']), 'application/pdf', 'a.pdf');
    expect($already)->toMatchArray(['converted' => false, 'level' => 'PDF/A-2b']);

    config(['digital_preservation.pdfa_driver' => 'unknown']);
    expect(fn () => $service->ensureArchival('x', 'image/png', 'a.png'))->toThrow(PreservationException::class);
});
