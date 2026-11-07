<?php

declare(strict_types=1);

use App\Services\Backup\TenantBackupService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Customs\Enums\SignatureStatus;
use Modules\Customs\Exceptions\CustomsException;
use Modules\Customs\Jobs\RetrieveAssessmentDecisionsJob;
use Modules\Customs\Models\CustomsAssessmentDecision;
use Modules\Customs\Models\CustomsRetrievalRun;
use Modules\Customs\Services\AssessmentArchive;
use Modules\Customs\Services\AssessmentDecisionParser;
use Modules\Customs\Services\AssessmentRetrievalService;
use Modules\Customs\Services\Edec\WsSecuritySignatureVerifier;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('private');
    $this->tenant = customsTenant();
    $this->user = customsMember($this->tenant);
    customsSettings($this->tenant);
});

afterEach(fn () => app(TenantContext::class)->clear());

test('the official samples of BAZG do not pass its own XSD: it is reported, and they are still read', function (): void {
    expect(app(AssessmentDecisionParser::class)->parse(customsOfficialFixture())->schemaErrors)->not->toBe([]);

    $decision = customsArchive($this->tenant, customsVatDecisionXml());

    expect($decision->signature_report['schema_errors'])->not->toBe([]);
});

test('the official BAZG sample of a VAT decision is read', function (): void {
    $parsed = app(AssessmentDecisionParser::class)->parse(customsOfficialFixture());

    expect($parsed->mrn)->toBe('15CHEI000005571286')
        ->and($parsed->version)->toBe(1)
        ->and($parsed->documentDate)->toBe('2015-07-07')
        ->and($parsed->vatAmount)->toBe('17.30')
        ->and($parsed->documentType->direction())->toBe('import')
        ->and($parsed->vatByRate)->not->toBe([]);
});

test('the official BAZG sample of an export decision is read', function (): void {
    $parsed = app(AssessmentDecisionParser::class)->parse(customsOfficialFixture('edecReceiptResponse_taxationDecisionExport.xml'));

    expect($parsed->documentType->direction())->toBe('export')->and($parsed->vatAmount)->toBe('0');
});

test('a decision is stored once on the private disk with its hashes', function (): void {
    $decision = customsArchive($this->tenant, customsVatDecisionXml(), pdf: '%PDF-sample');

    Storage::disk('private')->assertExists($decision->xml_path);

    expect($decision->xml_sha256)->toBe(hash('sha256', customsVatDecisionXml()))
        ->and($decision->signature_status)->toBe(SignatureStatus::NotSigned)
        ->and(customsArchive($this->tenant, customsVatDecisionXml())->id)->toBe($decision->id)
        ->and(CustomsAssessmentDecision::query()->count())->toBe(1)
        ->and(customsArchive($this->tenant, customsVatDecisionXml(version: 2))->id)->not->toBe($decision->id);
});

test('the database refuses any change to a decision', function (string $statement): void {
    customsArchive($this->tenant, customsVatDecisionXml());

    expect(fn () => DB::statement($statement))->toThrow(QueryException::class, 'immutable');
})->with([
    'update' => 'UPDATE customs_assessment_decisions SET vat_amount = 0',
    'delete' => 'DELETE FROM customs_assessment_decisions',
    'truncate' => 'TRUNCATE customs_assessment_decisions CASCADE',
]);

test('the model cannot get around the trigger', function (): void {
    $decision = customsArchive($this->tenant, customsVatDecisionXml());

    expect(fn () => $decision->update(['vat_amount' => '0']))->toThrow(QueryException::class)
        ->and(fn () => $decision->delete())->toThrow(QueryException::class);
});

test('a restore keeps the table as append-only', function (): void {
    expect(TenantBackupService::APPEND_ONLY_TABLES)->toContain('customs_assessment_decisions');
});

test('an altered file is detected', function (): void {
    $decision = customsArchive($this->tenant, customsVatDecisionXml());
    customsApi($this->user, $this->tenant)->getJson("/api/v1/customs/assessment-decisions/{$decision->id}/integrity")->assertOk()->assertJsonPath('data.is_intact', true);

    Storage::disk('private')->put($decision->xml_path, 'changed');

    $this->getJson("/api/v1/customs/assessment-decisions/{$decision->id}/integrity")->assertJsonPath('data.is_intact', false);
});

test('a decision without identity or with an unreadable amount is refused', function (string $from, string $to): void {
    expect(fn () => app(AssessmentDecisionParser::class)->parse(str_replace($from, $to, customsOfficialFixture())))->toThrow(CustomsException::class);
})->with([
    'no number' => ['<documentNumber>15CHEI000005571286</documentNumber>', ''],
    'no date' => ['<documentDate>2015-07-07</documentDate>', ''],
    'bad amount' => ['<totalAmount>17.30</totalAmount>', '<totalAmount>abc</totalAmount>'],
]);

test('a file that is not a decision is refused and leaves nothing', function (): void {
    customsApi($this->user, $this->tenant)->post('/api/v1/customs/assessment-decisions/upload', [
        'xml' => UploadedFile::fake()->createWithContent('x.xml', '<nothing/>'),
    ], ['Accept' => 'application/json'])->assertUnprocessable();

    expect(CustomsAssessmentDecision::query()->count())->toBe(0);
});

test('an XML with an external entity is not resolved', function (): void {
    $secret = tempnam(sys_get_temp_dir(), 'secret');
    file_put_contents($secret, 'TOPSECRET');
    $evil = '<?xml version="1.0"?><!DOCTYPE d [<!ENTITY x SYSTEM "file://'.$secret.'">]><d>&x;</d>';

    expect(fn () => app(AssessmentDecisionParser::class)->parse($evil))->toThrow(CustomsException::class, 'not a valid XML');
});

test('a decision is uploaded, found by MRN, type and date and downloaded as stored', function (): void {
    $xml = customsVatDecisionXml();

    customsApi($this->user, $this->tenant)->post('/api/v1/customs/assessment-decisions/upload', [
        'xml' => UploadedFile::fake()->createWithContent('vv.xml', $xml),
    ], ['Accept' => 'application/json'])->assertCreated()->assertJsonMissingPath('data.xml_path');

    customsArchive($this->tenant, customsOfficialFixture('edecReceiptResponse_taxationDecisionExport.xml'));

    $this->getJson('/api/v1/customs/assessment-decisions?mrn=26CHEI')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/customs/assessment-decisions?direction=export')->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/customs/assessment-decisions?document_type=taxationDecisionVAT')->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/customs/assessment-decisions?date_from=2026-10-01&date_to=2026-10-31')->assertJsonCount(1, 'data');

    $id = CustomsAssessmentDecision::query()->where('mrn', '26CHEI000000000001')->value('id');

    expect($this->get("/api/v1/customs/assessment-decisions/{$id}/xml")->assertOk()->getContent())->toBe($xml)
        ->and(customsResponseIsPdf($this->get("/api/v1/customs/assessment-decisions/{$id}/pdf")->assertOk()))->toBeTrue();

    $this->get("/api/v1/customs/assessment-decisions/{$id}/xml?signed=1")->assertNotFound();
});

test('a decision of another company stays out of reach', function (): void {
    $decision = customsArchive($this->tenant, customsVatDecisionXml());

    $other = customsTenant();
    customsApi(customsMember($other), $other)->getJson("/api/v1/customs/assessment-decisions/{$decision->id}")->assertNotFound();
    $this->get("/api/v1/customs/assessment-decisions/{$decision->id}/xml", ['Accept' => 'application/json'])->assertNotFound();
    $this->getJson('/api/v1/customs/assessment-decisions')->assertJsonCount(0, 'data');
});

test('the same MRN can live in two companies', function (): void {
    customsArchive($this->tenant, customsVatDecisionXml());
    $other = customsTenant();
    $decision = customsArchive($other, customsVatDecisionXml());

    expect($decision->tenant_id)->toBe($other->id)->and(CustomsAssessmentDecision::withoutGlobalScopes()->count())->toBe(2);
});

test('the signature of a message signed as BAZG shows is verified with openssl', function (): void {
    $pki = customsTestPki();
    $envelope = customsSignedEnvelope(customsVatDecisionXml(), $pki);

    $check = (new WsSecuritySignatureVerifier([$pki['anchor']]))->verify($envelope);

    expect($check->status)->toBe(SignatureStatus::Verified)
        ->and($check->report['body_signed'])->toBeTrue()
        ->and($check->report['chain_valid'])->toBeTrue()
        ->and($check->report['revocation_checked'])->toBeFalse();
});

test('a changed decision fails the check', function (): void {
    $pki = customsTestPki();
    $envelope = str_replace('<totalAmount>17.30', '<totalAmount>1.30', customsSignedEnvelope(customsVatDecisionXml(), $pki));

    $check = (new WsSecuritySignatureVerifier([$pki['anchor']]))->verify($envelope);

    expect($check->status)->toBe(SignatureStatus::Invalid)->and($check->report['references_valid'])->toBeFalse();
});

test('a signature wrapped around another body fails the check', function (): void {
    $pki = customsTestPki();
    $envelope = customsSignedEnvelope(customsVatDecisionXml(), $pki);

    $wrapped = str_replace('wsu:Id="id-body"', 'wsu:Id="other"', $envelope);
    $check = (new WsSecuritySignatureVerifier([$pki['anchor']]))->verify($wrapped);

    expect($check->status)->toBe(SignatureStatus::Invalid)->and($check->report['body_signed'])->toBeFalse();
});

test('a certificate outside the trust anchors fails the check', function (): void {
    $pki = customsTestPki();

    $check = (new WsSecuritySignatureVerifier([customsTestPki()['anchor']]))->verify(customsSignedEnvelope(customsVatDecisionXml(), $pki));

    expect($check->status)->toBe(SignatureStatus::Invalid)->and($check->report['signature_valid'])->toBeTrue()->and($check->report['chain_valid'])->toBeFalse();
});

test('the published BAZG anchors do not trust a certificate of ours', function (): void {
    $check = app(WsSecuritySignatureVerifier::class)->verify(customsSignedEnvelope(customsVatDecisionXml(), customsTestPki()));

    expect($check->status)->toBe(SignatureStatus::Invalid)->and($check->report['chain_valid'])->toBeFalse();
});

test('a message signed outside the validity of its certificate fails the check', function (): void {
    $pki = customsTestPki();

    $check = (new WsSecuritySignatureVerifier([$pki['anchor']]))->verify(customsSignedEnvelope(customsVatDecisionXml(), $pki, '+2 years'));

    expect($check->status)->toBe(SignatureStatus::Invalid)->and($check->report['valid_at_signing'])->toBeFalse();
});

test('a message without signature is reported as such', function (): void {
    $check = app(WsSecuritySignatureVerifier::class)->verify('<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"><soapenv:Body/></soapenv:Envelope>');

    expect($check->status)->toBe(SignatureStatus::NotSigned);
});

test('an uploaded signed message keeps the envelope and flags a failed signature', function (): void {
    $envelope = customsSignedEnvelope(customsVatDecisionXml(), customsTestPki());

    $response = customsApi($this->user, $this->tenant)->post('/api/v1/customs/assessment-decisions/upload', [
        'xml' => UploadedFile::fake()->createWithContent('signed.xml', $envelope),
    ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.signature_status', 'invalid');

    expect($this->get('/api/v1/customs/assessment-decisions/'.$response->json('data.id').'/xml?signed=1')->assertOk()->getContent())->toBe($envelope);
});

test('the control list flags what needs a look', function (): void {
    $this->travelTo('2026-11-07 06:00:00');
    $declaration = customsDeclaration($this->tenant, $this->user);
    $declaration->update(['mrn' => '26CHEI000000000002']);

    customsArchive($this->tenant, customsVatDecisionXml('26CHEI000000000001'));
    customsArchive($this->tenant, customsVatDecisionXml('26CHEI000000000002'));
    $altered = customsArchive($this->tenant, customsVatDecisionXml('26CHEI000000000003'));
    Storage::disk('private')->put($altered->xml_path, 'changed');
    $this->travelTo('2026-11-08 06:00:00');
    customsArchive($this->tenant, customsVatDecisionXml('26CHEI000000000004'));

    $data = customsApi($this->user, $this->tenant)->getJson('/api/v1/customs/assessment-decisions/control-list?date=2026-11-07')->assertOk()->json('data');
    $flags = collect($data['decisions'])->pluck('flags', 'mrn');

    expect($data['summary']['decisions'])->toBe(3)
        ->and($data['summary']['vat_total'])->toBe('51.90')
        ->and($flags['26CHEI000000000001'])->toContain('no_declaration', 'signature_unchecked', 'schema_mismatch', 'not_posted')
        ->and($flags['26CHEI000000000002'])->not->toContain('no_declaration')
        ->and($flags['26CHEI000000000003'])->toContain('file_altered');
});

test('statistics group by month, direction and type', function (): void {
    customsArchive($this->tenant, customsVatDecisionXml('26CHEI000000000001', date: '2026-10-15'));
    customsArchive($this->tenant, customsVatDecisionXml('26CHEI000000000002', date: '2026-10-20'));
    customsArchive($this->tenant, customsVatDecisionXml('26CHEI000000000003', date: '2026-11-02'));
    customsArchive($this->tenant, customsDutyDecisionXml('26CHEI000000000001', date: '2026-10-15'));

    $rows = customsApi($this->user, $this->tenant)->getJson('/api/v1/customs/assessment-decisions/statistics?date_from=2026-01-01&date_to=2026-12-31')->assertOk()->json('data');

    expect($rows)->toHaveCount(3)
        ->and($rows[0])->toMatchArray(['month' => '2026-10', 'document_type' => 'taxationDecisionCustomsDuties', 'decisions' => 1, 'duty_total' => '250.00'])
        ->and($rows[1])->toMatchArray(['month' => '2026-10', 'document_type' => 'taxationDecisionVAT', 'decisions' => 2, 'vat_total' => '34.60']);
});

test('the nightly job runs only for companies that opted in', function (): void {
    (new RetrieveAssessmentDecisionsJob($this->tenant->id))->handle(app(AssessmentRetrievalService::class));

    expect(CustomsRetrievalRun::withoutGlobalScopes()->count())->toBe(0);

    DB::table('customs_settings')->where('tenant_id', $this->tenant->id)->update(['auto_retrieve' => true]);
    app(TenantContext::class)->setTenant($this->tenant);
    (new RetrieveAssessmentDecisionsJob($this->tenant->id))->handle(app(AssessmentRetrievalService::class));

    expect(CustomsRetrievalRun::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->sole()->status)->toBe('completed');
});

test('the retrieval is scheduled through the tenant dispatcher', function (): void {
    $names = collect(app(Schedule::class)->events())->map(fn ($event): ?string => $event->description)->all();

    expect($names)->toContain('customs:assessment-decisions');
});

test('a run on demand answers with its outcome', function (): void {
    customsApi($this->user, $this->tenant)->postJson('/api/v1/customs/assessment-decisions/retrieve', ['date_from' => '2026-11-01', 'date_to' => '2026-11-02'])
        ->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.gateway', 'local');

    $this->getJson('/api/v1/customs/assessment-decisions/retrieval-runs')->assertOk()->assertJsonCount(1, 'data');
});

test('archived content is never overwritten', function (): void {
    $decision = customsArchive($this->tenant, customsVatDecisionXml());
    Storage::disk('private')->put($decision->xml_path, 'other');
    DB::unprepared('ALTER TABLE customs_assessment_decisions DISABLE TRIGGER trg_prevent_customs_assessment_mutation');
    DB::table('customs_assessment_decisions')->delete();
    DB::unprepared('ALTER TABLE customs_assessment_decisions ENABLE TRIGGER trg_prevent_customs_assessment_mutation');

    expect(fn () => customsArchive($this->tenant, customsVatDecisionXml()))->toThrow(CustomsException::class, 'different content');
});

test('the archive service reads back what it stored', function (): void {
    $decision = customsArchive($this->tenant, customsVatDecisionXml());

    expect(app(AssessmentArchive::class)->contents($decision->xml_path))->toBe(customsVatDecisionXml());
});
