<?php

declare(strict_types=1);

use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Modules\Customs\DTOs\AssessmentReference;
use Modules\Customs\Enums\AssessmentDocumentType;
use Modules\Customs\Enums\CustomsChannel;
use Modules\Customs\Exceptions\CustomsGatewayException;
use Modules\Customs\Gateways\EdecGateway;
use Modules\Customs\Gateways\LocalCustomsGateway;
use Modules\Customs\Models\CustomsAssessmentDecision;
use Modules\Customs\Models\CustomsRetrievalRun;
use Modules\Customs\Models\CustomsSettings;
use Modules\Customs\Services\AssessmentRetrievalService;
use Modules\Customs\Services\CustomsGatewayManager;
use Modules\Customs\Services\Edec\EdecSchemaValidator;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

const CUSTOMS_ENDPOINT = 'https://edec.test.invalid/services';

/**
 * Opens the e-dec channel towards a fake endpoint.
 */
function customsLiveConfig(): void
{
    $certificate = tempnam(sys_get_temp_dir(), 'client');

    config([
        'customs.edec.live_transmission' => true,
        'customs.edec.declaration_endpoint' => CUSTOMS_ENDPOINT.'/EdecService/v4',
        'customs.edec.receipt_endpoint' => CUSTOMS_ENDPOINT.'/EdecReceiptService/v3',
        'customs.edec.client_certificate' => $certificate,
        'customs.edec.client_key' => $certificate,
    ]);
}

function customsSoap(string $body): string
{
    return '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"><soapenv:Body>'.preg_replace('/^<\?xml[^>]*\?>\s*/', '', $body).'</soapenv:Body></soapenv:Envelope>';
}

beforeEach(function (): void {
    Storage::fake('private');
    Http::preventStrayRequests();
    $this->tenant = customsTenant();
    $this->user = customsMember($this->tenant);
    $this->settings = customsSettings($this->tenant, ['channel' => 'edec']);
    customsApi($this->user, $this->tenant);

    $this->declaration = function (): string {
        $id = $this->postJson('/api/v1/customs/declarations', customsImportPayload(customsContact($this->tenant)))->json('data.id');
        $this->postJson("/api/v1/customs/declarations/{$id}/validate")->assertJsonPath('data.status', 'validated');

        return $id;
    };
});

afterEach(fn () => app(TenantContext::class)->clear());

test('the manager picks the channel of the company', function (): void {
    expect(app(CustomsGatewayManager::class)->for($this->tenant))->toBeInstanceOf(EdecGateway::class);

    $other = customsTenant();

    expect(app(CustomsGatewayManager::class)->channelFor($other))->toBe(CustomsChannel::Local)
        ->and(app(CustomsGatewayManager::class)->for($other))->toBeInstanceOf(LocalCustomsGateway::class);
});

test('a real send refuses to run while live transmission is off, and nothing leaves', function (): void {
    $id = ($this->declaration)();

    $this->postJson("/api/v1/customs/declarations/{$id}/submit")->assertStatus(502)->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'switched off'));

    Http::assertNothingSent();
    $this->getJson("/api/v1/customs/declarations/{$id}")->assertJsonPath('data.status', 'validated');
});

test('the live switch alone is not enough', function (array $config, string $reason): void {
    customsLiveConfig();
    config($config);
    $id = ($this->declaration)();

    $this->postJson("/api/v1/customs/declarations/{$id}/submit")->assertStatus(502)->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, $reason));

    Http::assertNothingSent();
})->with([
    'no endpoint' => [['customs.edec.declaration_endpoint' => null], 'endpoint'],
    'plain http' => [['customs.edec.declaration_endpoint' => 'http://edec.test.invalid'], 'endpoint'],
    'no certificate' => [['customs.edec.client_certificate' => null], 'certificate'],
    'missing certificate file' => [['customs.edec.client_key' => '/no/such/file.key'], 'certificate'],
]);

test('e-dec import closes on the day published by BAZG', function (): void {
    customsLiveConfig();
    $id = ($this->declaration)();
    $this->travelTo('2027-10-01 09:00:00');

    $this->postJson("/api/v1/customs/declarations/{$id}/submit")->assertStatus(502)->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, '2027-09-30'));

    Http::assertNothingSent();
});

test('an accepted declaration gets its MRN from the signed-off SOAP answer', function (): void {
    customsLiveConfig();
    $id = ($this->declaration)();

    Http::fake([CUSTOMS_ENDPOINT.'/*' => Http::response(customsSoap(
        '<goodsDeclarationsResponse xmlns="http://www.e-dec.ch/xml/schema/edecResponse/v4" schemaVersion="4.0"><goodsDeclarationAcceptance><traderDeclarationNumber>1</traderDeclarationNumber><requestorTraderIdentificationNumber>1</requestorTraderIdentificationNumber><customsDeclarationNumber>26CHEI000000000009</customsDeclarationNumber><customsDeclarationVersion>1</customsDeclarationVersion><accessCode>abc</accessCode><acceptanceDate>2026-11-07</acceptanceDate><acceptanceTime>10:00:00</acceptanceTime><declarant><traderIdentificationNumber>CHE1</traderIdentificationNumber></declarant></goodsDeclarationAcceptance></goodsDeclarationsResponse>'
    ), 200, ['Content-Type' => 'text/xml'])]);

    $response = $this->postJson("/api/v1/customs/declarations/{$id}/submit");

    expect($response->status())->toBe(200, (string) $response->getContent());
    $response->assertJsonPath('data.status', 'accepted')->assertJsonPath('data.mrn', '26CHEI000000000009')->assertJsonPath('data.gateway', 'edec');

    Http::assertSent(function (Request $request): bool {
        $inner = str_replace(['<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/"><SOAP-ENV:Header/><SOAP-ENV:Body>', '</SOAP-ENV:Body></SOAP-ENV:Envelope>'], '', $request->body());

        return $request->hasHeader('SOAPAction', '"goodsDeclarations"')
            && app(EdecSchemaValidator::class)->errors($inner, EdecSchemaValidator::DECLARATION) === [];
    });
});

test('a SOAP fault is a channel error and the declaration can be sent again', function (): void {
    customsLiveConfig();
    $id = ($this->declaration)();

    Http::fake([CUSTOMS_ENDPOINT.'/*' => Http::response(customsSoap('<soapenv:Fault><faultcode>Client</faultcode><faultstring>Spediteurnummer falsch</faultstring></soapenv:Fault>'), 500)]);

    $this->postJson("/api/v1/customs/declarations/{$id}/submit")->assertStatus(502)->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'Spediteurnummer falsch'));
    $this->getJson("/api/v1/customs/declarations/{$id}")->assertJsonPath('data.status', 'validated');
});

test('a rejection is stored with the reasons of customs', function (): void {
    customsLiveConfig();
    $id = ($this->declaration)();

    Http::fake([CUSTOMS_ENDPOINT.'/*' => Http::response(customsSoap(
        '<goodsDeclarationsResponse xmlns="http://www.e-dec.ch/xml/schema/edecResponse/v4" schemaVersion="4.0"><goodsDeclarationRejection><rejectionDate>2026-11-07</rejectionDate><rejectionTime>10:00:00</rejectionTime><errors><ruleErrors><ruleError><ruleID>R1</ruleID><description language="de">Tarifnummer unbekannt</description></ruleError></ruleErrors></errors></goodsDeclarationRejection></goodsDeclarationsResponse>'
    ))]);

    $this->postJson("/api/v1/customs/declarations/{$id}/submit")->assertOk()
        ->assertJsonPath('data.status', 'rejected')
        ->assertJsonPath('data.rejection_reason', fn (string $reason): bool => str_contains($reason, 'Tarifnummer unbekannt'));

    $this->putJson("/api/v1/customs/declarations/{$id}", ['trader_reference' => 'corretta'])->assertOk()->assertJsonPath('data.status', 'draft');
});

test('the official list answer gives the references, and the request passes the schema', function (): void {
    customsLiveConfig();
    $this->settings->update(['receipt_role' => 1]);

    Http::fake([CUSTOMS_ENDPOINT.'/*' => Http::response(customsSoap(customsOfficialFixture('edecReceiptResponse_taxationDecisionVATImport_list.xml')))]);

    $references = app(EdecGateway::class)->listAssessments($this->tenant, CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-05'));

    expect($references[0])->toBeInstanceOf(AssessmentReference::class)
        ->and($references[0]->mrn)->toBe('15CHEI000005571286')
        ->and($references[0]->version)->toBe(3)
        ->and($references[0]->documentType)->toBe(AssessmentDocumentType::Vat);

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('SOAPAction', '"receiptRequest"') && str_contains($request->body(), '<accountNumber>10000</accountNumber>'));
});

test('a list over the BAZG limit is refused before anything is sent', function (): void {
    customsLiveConfig();

    expect(fn () => app(EdecGateway::class)->listAssessments($this->tenant, CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-20')))
        ->toThrow(CustomsGatewayException::class, '10 days');

    Http::assertNothingSent();
});

test('the daily retrieval archives, skips what it has and records the failures', function (): void {
    customsLiveConfig();
    $decision = customsVatDecisionXml('15CHEI000005571286', 3, '2015-07-08');

    Http::fakeSequence(CUSTOMS_ENDPOINT.'/*')
        ->push(customsSoap(customsOfficialFixture('edecReceiptResponse_taxationDecisionVATImport_list.xml')))
        ->push(customsSoap('<receiptRequestResponse xmlns="http://www.e-dec.ch/xml/schema/edecReceiptResponse/v3" schemaVersion="3.0"><requestorTraderIdentificationNumber>1</requestorTraderIdentificationNumber><receiptList><receiptRequestSummary><numberOfDocumentsFound>0</numberOfDocumentsFound></receiptRequestSummary></receiptList></receiptRequestResponse>'))
        ->push(customsSoap($decision))
        ->whenEmpty(Http::response(customsSoap('<soapenv:Fault><faultcode>x</faultcode><faultstring>V3 Zeitfenster abgelaufen</faultstring></soapenv:Fault>'), 500));

    $run = app(AssessmentRetrievalService::class)->run($this->tenant, CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-05'));

    expect($run->status)->toBe('partial')
        ->and($run->listed_count)->toBeGreaterThan(1)
        ->and($run->stored_count)->toBe(1)
        ->and($run->failed_count)->toBe($run->listed_count - 1)
        ->and($run->errors[0]['error'])->toContain('V3')
        ->and(CustomsAssessmentDecision::query()->where('mrn', '15CHEI000005571286')->value('gateway'))->toBe('edec');
});

test('a channel that is closed leaves a failed run, not an exception', function (): void {
    $run = app(AssessmentRetrievalService::class)->run($this->tenant, CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-05'));

    expect($run->status)->toBe('failed')
        ->and($run->errors[0]['error'])->toContain('switched off')
        ->and(CustomsRetrievalRun::query()->count())->toBe(1);
});

test('the settings refuse a role that is for internal use and hold no secret', function (): void {
    $this->putJson('/api/v1/customs/settings', ['receipt_role' => 3])->assertUnprocessable();
    $this->putJson('/api/v1/customs/settings', ['channel' => 'local', 'auto_retrieve' => true])->assertOk()->assertJsonPath('data.channel', 'local');

    expect(CustomsSettings::query()->count())->toBe(1)
        ->and($this->getJson('/api/v1/customs/settings')->json('data'))->not->toHaveKey('client_key');
});
