<?php

declare(strict_types=1);

use App\Models\PassiveInvoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Enterprise\AiGateway\Contracts\AiDriverInterface;
use Enterprise\AiGateway\DTOs\AiPayload;
use Enterprise\AiGateway\DTOs\AiResponse;
use Enterprise\AiGateway\Services\AiDriverRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Documents\Models\DmsDocument;
use Modules\Documents\Models\TenantAiSetting;
use Modules\GeneralLedger\Models\JournalEntry;

uses(RefreshDatabase::class);

function extractionActAs(Tenant $tenant, string $role = 'admin'): User
{
    $user = User::create(['name' => $role, 'email' => Str::uuid().'@example.com', 'password' => 'secret123']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);
    app(TenantContext::class)->clear();
    Sanctum::actingAs($user);
    test()->withHeader('X-Tenant-Id', $tenant->id);

    return $user;
}

function extractionDocument(string $content = "Fattura n. FT-2026/17\nData 2026-03-10\nTotale EUR 1'250.50\nIBAN CH93 0076 2011 6238 5295 7"): string
{
    return test()->post('/api/v1/dms/documents', [
        'title' => 'Scan',
        'file' => UploadedFile::fake()->createWithContent('scan.txt', $content),
    ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
}

function registerExternalDriver(bool &$called): void
{
    $driver = new class($called) implements AiDriverInterface
    {
        public function __construct(private bool &$called) {}

        public function getName(): string
        {
            return 'external-test';
        }

        public function executeInference(AiPayload $payload): AiResponse
        {
            $this->called = true;

            return new AiResponse(success: true, content: '{"fields":[{"name":"invoice_number","value":"X-1","confidence":0.9},{"name":"drop_table","value":"x"}]}', driverUsed: 'external-test');
        }

        public function generateEmbeddings(string $text, ?string $model = null): array
        {
            return [];
        }
    };

    app(AiDriverRegistry::class)->register('external-test', $driver);
    config(['ai.extraction_driver' => 'external-test']);
}

beforeEach(function (): void {
    Storage::fake('local');
    config(['filesystems.private_disk' => 'local']);
    $this->tenant = Tenant::create(['name' => 'Alfa', 'legal_name' => 'Alfa SA']);
    extractionActAs($this->tenant);
});

afterEach(fn () => app(TenantContext::class)->clear());

test('the local driver proposes fields for review and nothing is posted', function (): void {
    $id = extractionDocument();

    $data = $this->postJson("/api/v1/dms/documents/{$id}/extractions")->assertCreated()->json('data');
    $fields = collect($data['proposed_fields'])->pluck('value', 'name');

    expect($data['status'])->toBe('proposed')
        ->and($data['driver'])->toBe('local')
        ->and($fields['invoice_number'])->toBe('FT-2026/17')
        ->and($fields['invoice_date'])->toBe('2026-03-10')
        ->and($fields['currency'])->toBe('EUR')
        ->and(PassiveInvoice::query()->count())->toBe(0)
        ->and(JournalEntry::query()->count())->toBe(0)
        ->and(DmsDocument::query()->findOrFail($id)->metadata)->toBe([]);
});

test('confirming stores the values reviewed by the person, and only once', function (): void {
    $id = extractionDocument();
    $extraction = $this->postJson("/api/v1/dms/documents/{$id}/extractions")->json('data.id');

    $this->postJson("/api/v1/dms/extractions/{$extraction}/confirm", ['fields' => ['invoice_number' => 'FT-17-CORRECTED']])->assertOk()
        ->assertJsonPath('data.status', 'confirmed');

    expect(DmsDocument::query()->findOrFail($id)->metadata['extracted']['invoice_number'])->toBe('FT-17-CORRECTED')
        ->and(PassiveInvoice::query()->count())->toBe(0);

    $this->postJson("/api/v1/dms/extractions/{$extraction}/confirm", ['fields' => ['invoice_number' => 'AGAIN']])->assertConflict();
    $this->postJson("/api/v1/dms/extractions/{$extraction}/reject")->assertConflict();
});

test('rejecting leaves the document as it was', function (): void {
    $id = extractionDocument();
    $extraction = $this->postJson("/api/v1/dms/documents/{$id}/extractions")->json('data.id');

    $this->postJson("/api/v1/dms/extractions/{$extraction}/reject")->assertOk()->assertJsonPath('data.status', 'rejected');

    expect(DmsDocument::query()->findOrFail($id)->metadata)->toBe([]);
});

test('a format without text is recorded as failed, not guessed', function (): void {
    $id = $this->post('/api/v1/dms/documents', ['title' => 'Image', 'file' => UploadedFile::fake()->image('scan.png')], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

    $this->postJson("/api/v1/dms/documents/{$id}/extractions")->assertCreated()
        ->assertJsonPath('data.status', 'failed')
        ->assertJsonPath('data.proposed_fields', []);
});

test('an external driver receives nothing until the tenant enables it', function (): void {
    $called = false;
    registerExternalDriver($called);
    $id = extractionDocument();

    $this->postJson("/api/v1/dms/documents/{$id}/extractions")->assertForbidden();
    expect($called)->toBeFalse();

    TenantAiSetting::create(['tenant_id' => $this->tenant->id, 'external_providers_enabled' => false]);
    $this->postJson("/api/v1/dms/documents/{$id}/extractions")->assertForbidden();
    expect($called)->toBeFalse();
});

test('after explicit consent the external driver runs and unknown fields are dropped', function (): void {
    $called = false;
    registerExternalDriver($called);
    $id = extractionDocument();

    $this->putJson('/api/v1/dms/ai-settings', ['external_providers_enabled' => true])->assertOk();

    $data = $this->postJson("/api/v1/dms/documents/{$id}/extractions")->assertCreated()->json('data');

    expect($called)->toBeTrue()
        ->and($data['driver'])->toBe('external-test')
        ->and(collect($data['proposed_fields'])->pluck('name')->all())->toBe(['invoice_number']);
});

test('consent belongs to one tenant', function (): void {
    $called = false;
    registerExternalDriver($called);
    $this->putJson('/api/v1/dms/ai-settings', ['external_providers_enabled' => true])->assertOk();

    extractionActAs(Tenant::create(['name' => 'Beta', 'legal_name' => 'Beta SA']));
    $id = extractionDocument('other tenant content');

    $this->getJson('/api/v1/dms/ai-settings')->assertOk()->assertJsonPath('data.external_providers_enabled', false);
    $this->postJson("/api/v1/dms/documents/{$id}/extractions")->assertForbidden();
    expect($called)->toBeFalse();
});

test('only administrators change the consent', function (): void {
    extractionActAs($this->tenant, 'accountant');

    $this->putJson('/api/v1/dms/ai-settings', ['external_providers_enabled' => true])->assertForbidden();
    $this->getJson('/api/v1/dms/ai-settings')->assertForbidden();
});

test('a failing local driver never falls back to an external one', function (): void {
    $called = false;
    $external = new class($called) implements AiDriverInterface
    {
        public function __construct(private bool &$called) {}

        public function getName(): string
        {
            return 'openai';
        }

        public function executeInference(AiPayload $payload): AiResponse
        {
            $this->called = true;

            return new AiResponse(success: true, content: '{"fields":[]}');
        }

        public function generateEmbeddings(string $text, ?string $model = null): array
        {
            return [];
        }
    };
    $failing = new class implements AiDriverInterface
    {
        public function getName(): string
        {
            return 'local';
        }

        public function executeInference(AiPayload $payload): AiResponse
        {
            return new AiResponse(success: false, content: '', errorMessage: 'down');
        }

        public function generateEmbeddings(string $text, ?string $model = null): array
        {
            return [];
        }
    };

    $registry = app(AiDriverRegistry::class);
    $registry->register('openai', $external);
    $registry->register('local', $failing);
    $registry->setFallbackDriver('openai');

    $id = extractionDocument();

    $this->postJson("/api/v1/dms/documents/{$id}/extractions")->assertCreated()->assertJsonPath('data.status', 'failed');
    expect($called)->toBeFalse();
});

test('a role that cannot see the document cannot extract from it', function (): void {
    $id = $this->post('/api/v1/dms/documents', ['title' => 'HR', 'access_roles' => ['hr'], 'file' => UploadedFile::fake()->createWithContent('a.txt', 'secret')], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
    $extraction = $this->postJson("/api/v1/dms/documents/{$id}/extractions")->json('data.id');

    extractionActAs($this->tenant, 'member');

    $this->postJson("/api/v1/dms/documents/{$id}/extractions")->assertNotFound();
    $this->postJson("/api/v1/dms/extractions/{$extraction}/confirm", ['fields' => ['a' => 'b']])->assertNotFound();
});
