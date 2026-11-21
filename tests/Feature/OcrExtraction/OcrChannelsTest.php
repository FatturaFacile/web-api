<?php

declare(strict_types=1);

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Modules\Documents\Models\DmsFolder;
use Modules\OcrExtraction\Domain\Enums\OcrProviderType;
use Modules\OcrExtraction\Infrastructure\Models\DocumentExtractionJob;
use Modules\OcrExtraction\Infrastructure\Models\TenantOcrConfiguration;

require_once __DIR__.'/OcrTestHelpers.php';

uses(RefreshDatabase::class);

/**
 * Posts a signed inbound-email webhook the way the mail gateway does.
 *
 * @param  array<string, mixed>  $body
 */
function ocrInboundEmail(object $test, array $body, ?string $secret = 'gateway-secret', ?string $signature = null): TestResponse
{
    $raw = (string) json_encode($body);
    $signature ??= 'sha256='.hash_hmac('sha256', $raw, (string) $secret);

    return $test->call('POST', '/api/v1/webhooks/inbound-email', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_SIGNATURE' => $signature], $raw);
}

function ocrAliasOf(string $tenantId): string
{
    return (string) DB::table('tenant_ocr_configurations')->where('tenant_id', $tenantId)->value('dedicated_inbox_alias');
}

beforeEach(function (): void {
    config(['ocr_extraction.inbound_email_secret' => 'gateway-secret']);
});

test('a file uploaded to the AI Inbox folder of the DMS enters the queue, a file in another folder does not', function (): void {
    Http::fake();
    $company = ocrCompany('IT');
    ocrActAs($company['tenant']);
    $inbox = DmsFolder::withoutGlobalScopes()->create(['tenant_id' => $company['tenant']->id, 'name' => 'AI Inbox', 'access_roles' => []]);
    $other = DmsFolder::withoutGlobalScopes()->create(['tenant_id' => $company['tenant']->id, 'name' => 'Archive', 'access_roles' => []]);

    $queued = ocrDmsDocument($company['tenant'], ocrFatturaPaXml(), 'fattura.xml', 'application/xml', $inbox->id);
    $ignored = ocrDmsDocument($company['tenant'], ocrFatturaPaXml(), 'altro.xml', 'application/xml', $other->id);

    $job = DocumentExtractionJob::withoutGlobalScopes()->where('dms_document_id', $queued->id)->first();

    expect($job)->not->toBeNull()
        ->and($job->source_channel->value)->toBe('dms_upload')
        ->and($job->status->value)->toBe('ready_for_review')
        ->and(DocumentExtractionJob::withoutGlobalScopes()->where('dms_document_id', $ignored->id)->exists())->toBeFalse();
});

test('the mobile capture endpoint queues a receipt photo, and an offline retry returns the same job', function (): void {
    Storage::fake('local');
    $company = ocrCompany('IT');
    ocrFakeQrBill(null);
    Http::fake(['localhost:11434/*' => Http::response(['message' => ['content' => json_encode(ocrSwissModelAnswer())], 'prompt_eval_count' => 3, 'eval_count' => 4])]);
    ocrActAs($company['tenant'], 'member');

    $photo = fn (): UploadedFile => UploadedFile::fake()->createWithContent('receipt.png', ocrPng());
    $first = $this->post('/api/v1/ocr-inbox/capture', ['file' => $photo(), 'document_category' => 'expense_receipt', 'client_reference' => 'device-1-photo-42', 'captured_at' => '2026-03-05T10:15:00Z', 'device' => 'Pixel 9'], ['Accept' => 'application/json'])
        ->assertStatus(202)
        ->assertJsonPath('data.source_channel', 'mobile_camera')
        ->assertJsonPath('data.document_category', 'expense_receipt')
        ->assertJsonPath('data.status', 'ready_for_review');

    $retry = $this->post('/api/v1/ocr-inbox/capture', ['file' => $photo(), 'client_reference' => 'device-1-photo-42'], ['Accept' => 'application/json'])->assertStatus(202);

    expect($retry->json('data.id'))->toBe($first->json('data.id'))
        ->and(DocumentExtractionJob::withoutGlobalScopes()->count())->toBe(1)
        ->and(DB::table('dms_documents')->where('tenant_id', $company['tenant']->id)->count())->toBe(1)
        ->and((string) DB::table('dms_folders')->where('tenant_id', $company['tenant']->id)->value('name'))->toBe('AI Inbox');

    $this->post('/api/v1/ocr-inbox/capture', [], ['Accept' => 'application/json'])->assertUnprocessable();
    $this->post('/api/v1/ocr-inbox/capture', ['file' => UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')], ['Accept' => 'application/json'])->assertUnprocessable();
});

test('the pages of a multi-page capture are joined into one PDF', function (): void {
    Storage::fake('local');
    $company = ocrCompany('IT');
    ocrFakeQrBill(null);
    Http::fake(['localhost:11434/*' => Http::response('unavailable', 503)]);
    ocrActAs($company['tenant'], 'member');

    $pages = [UploadedFile::fake()->createWithContent('p1.png', ocrPng()), UploadedFile::fake()->createWithContent('p2.png', ocrPng())];
    $this->post('/api/v1/ocr-inbox/capture', ['pages' => $pages, 'document_category' => 'transport_ddt'], ['Accept' => 'application/json'])->assertStatus(202)->assertJsonPath('data.document_category', 'transport_ddt');

    $version = DB::table('dms_document_versions')->where('tenant_id', $company['tenant']->id)->first();

    expect($version->mime_type)->toBe('application/pdf')
        ->and(Storage::disk('local')->get($version->path))->toStartWith('%PDF')
        ->and(substr_count((string) Storage::disk('local')->get($version->path), '/Type /Page'))->toBeGreaterThanOrEqual(2);
});

test('the inbound e-mail webhook accepts only signed messages for a known mailbox and queues the attachments', function (): void {
    Http::fake();
    $company = ocrCompany('IT');
    $other = ocrCompany('IT');
    ocrConfigure($company['tenant'], OcrProviderType::OllamaLocal);
    ocrConfigure($other['tenant'], OcrProviderType::OllamaLocal);
    $user = ocrActAs($company['tenant'], 'owner');

    $body = [
        'to' => 'Invoices <'.strtoupper(ocrAliasOf((string) $company['tenant']->id)).'>',
        'from' => 'billing@supplier.example',
        'subject' => 'Invoice FT-17',
        'message_id' => '<abc-123@mail.example>',
        'attachments' => [
            ['filename' => 'fattura.xml', 'content_type' => 'text/xml', 'content_base64' => base64_encode(ocrFatturaPaXml())],
            ['filename' => 'notes.txt', 'content_type' => 'text/plain', 'content_base64' => base64_encode('hello')],
            ['filename' => 'broken.pdf', 'content_type' => 'application/pdf', 'content_base64' => '###not-base64###'],
        ],
    ];

    ocrInboundEmail($this, $body, signature: 'sha256=forged')->assertUnauthorized();
    ocrInboundEmail($this, $body, signature: 'nonsense')->assertUnauthorized();
    expect(DocumentExtractionJob::withoutGlobalScopes()->count())->toBe(0);

    $accepted = ocrInboundEmail($this, $body)->assertStatus(202)->assertJsonPath('data.accepted', 1)->assertJsonPath('data.skipped', ['notes.txt', 'broken.pdf']);
    $job = DocumentExtractionJob::withoutGlobalScopes()->findOrFail($accepted->json('data.job_ids.0'));

    expect($job->tenant_id)->toBe((string) $company['tenant']->id)
        ->and($job->source_channel->value)->toBe('tenant_email_inbox')
        ->and($job->status->value)->toBe('ready_for_review')
        ->and($job->vendor_id)->toBe($company['vendor']);

    // The gateway redelivers the same message: nothing is queued twice.
    $again = ocrInboundEmail($this, $body)->assertStatus(202);
    expect($again->json('data.job_ids.0'))->toBe($job->id)
        ->and(DocumentExtractionJob::withoutGlobalScopes()->count())->toBe(1)
        ->and(DocumentExtractionJob::withoutGlobalScopes()->where('tenant_id', $other['tenant']->id)->count())->toBe(0);

    ocrInboundEmail($this, [...$body, 'to' => 'invoices@nobody.fatturafacile.ch'])->assertNotFound();
    ocrInboundEmail($this, [...$body, 'attachments' => []])->assertUnprocessable();

    config(['ocr_extraction.inbound_email_secret' => '']);
    ocrInboundEmail($this, $body, secret: '')->assertStatus(503);
    expect($user->id)->not->toBeEmpty();
});

test('the engine configuration is changed by managers, its secrets are encrypted and never returned', function (): void {
    $company = ocrCompany('CH');
    ocrActAs($company['tenant'], 'owner');

    $this->getJson('/api/v1/ocr-inbox/configuration')->assertOk()
        ->assertJsonPath('data.primary_provider', 'ollama_local')
        ->assertJsonPath('data.auto_confirm_enabled', false)
        ->assertJsonPath('data.auto_confirm_confidence_threshold', 98);

    $saved = $this->putJson('/api/v1/ocr-inbox/configuration', [
        'primary_provider' => 'gemini_cloud',
        'fallback_provider' => 'tesseract_local',
        'provider_settings' => ['gemini_cloud' => ['api_key' => 'SECRET-GEMINI-KEY', 'model' => 'gemini-2.0-flash']],
        'auto_confirm_confidence_threshold' => 96.5,
    ])->assertOk()
        ->assertJsonPath('data.primary_provider', 'gemini_cloud')
        ->assertJsonPath('data.fallback_provider', 'tesseract_local')
        ->assertJsonPath('data.configured_providers.gemini_cloud', ['api_key', 'model'])
        ->assertJsonPath('data.auto_confirm_confidence_threshold', 96.5);

    expect($saved->getContent())->not->toContain('SECRET-GEMINI-KEY')
        ->and($saved->json('data.dedicated_inbox_alias'))->toEndWith('.fatturafacile.ch')->toStartWith('invoices@');

    $stored = (string) DB::table('tenant_ocr_configurations')->where('tenant_id', $company['tenant']->id)->value('provider_settings_encrypted');
    expect($stored)->not->toContain('SECRET-GEMINI-KEY')->not->toContain('gemini_cloud');

    // A blank key keeps the stored secret.
    $this->putJson('/api/v1/ocr-inbox/configuration', ['primary_provider' => 'gemini_cloud', 'provider_settings' => ['gemini_cloud' => ['api_key' => '', 'model' => 'gemini-2.5-flash']]])->assertOk();
    $configuration = app(TenantContext::class)->runWithTenant($company['tenant'], static fn () => TenantOcrConfiguration::query()->firstOrFail());

    expect($configuration->settingsFor(OcrProviderType::GeminiCloud))->toEqualCanonicalizing(['api_key' => 'SECRET-GEMINI-KEY', 'model' => 'gemini-2.5-flash'])
        ->and($configuration->auto_confirm_enabled)->toBeFalse();

    $this->putJson('/api/v1/ocr-inbox/configuration', ['primary_provider' => 'gemini_cloud', 'fallback_provider' => 'gemini_cloud'])->assertUnprocessable();
    $this->putJson('/api/v1/ocr-inbox/configuration', ['primary_provider' => 'skynet'])->assertUnprocessable();
    $this->putJson('/api/v1/ocr-inbox/configuration', ['primary_provider' => 'ollama_local', 'provider_settings' => ['skynet' => ['model' => 'x']]])->assertUnprocessable();
    $this->putJson('/api/v1/ocr-inbox/configuration', ['primary_provider' => 'azure_document_intelligence', 'provider_settings' => ['azure_document_intelligence' => ['endpoint' => 'https://evil.example.com', 'api_key' => 'k']]])->assertUnprocessable();
    $this->putJson('/api/v1/ocr-inbox/configuration', ['primary_provider' => 'azure_document_intelligence', 'provider_settings' => ['azure_document_intelligence' => ['endpoint' => 'https://acme.cognitiveservices.azure.com', 'api_key' => 'k']]])->assertOk();
});

test('touchless posting runs only when the tenant switched it on and the confidence and coding allow it', function (): void {
    Http::fake();
    $company = ocrCompany('IT');
    ocrActAs($company['tenant'], 'owner');

    // Learn the coding of the vendor from a first, manually confirmed invoice.
    $first = ocrReadyJob($this, $company, 'FT-1');
    ocrConfirm($this, $first, ocrReviewed($first, $company))->assertOk();

    // Switched off by default: the next invoice waits for review.
    $waiting = ocrReadyJob($this, $company, 'FT-2');
    expect($waiting->status->value)->toBe('ready_for_review');

    $this->putJson('/api/v1/ocr-inbox/configuration', ['primary_provider' => 'ollama_local', 'auto_confirm_enabled' => true, 'auto_confirm_confidence_threshold' => 95])->assertOk();

    $document = ocrDmsDocument($company['tenant'], ocrInvoiceXml('FT-3'), 'FT-3.xml', 'application/xml');
    $this->postJson('/api/v1/ocr-inbox/documents', ['dms_document_id' => $document->id])->assertStatus(202)
        ->assertJsonPath('data.status', 'confirmed')
        ->assertJsonPath('data.confirmed_by_user_id', null)
        ->assertJsonPath('data.resulting_entity_type', 'supplier_invoice');

    // An unknown vendor is never posted without a human.
    $xml = str_replace(['FT-17', '01234567890'], ['FT-4', '55555555555'], ocrFatturaPaXml());
    $stranger = ocrDmsDocument($company['tenant'], $xml, 'ft4.xml', 'application/xml');
    $this->postJson('/api/v1/ocr-inbox/documents', ['dms_document_id' => $stranger->id])->assertStatus(202)->assertJsonPath('data.status', 'ready_for_review');
    expect(DB::table('passive_invoices')->where('tenant_id', $company['tenant']->id)->count())->toBe(2);
});
