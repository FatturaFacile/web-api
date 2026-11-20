<?php

declare(strict_types=1);

use App\Contracts\SwissQrBill\SwissQrBillParserInterface;
use App\Exceptions\QrBillParseException;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SwissQrBill\ParsedQrBill;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Modules\Documents\Models\DmsDocument;
use Modules\Documents\Models\DmsDocumentVersion;
use Modules\Documents\Models\TenantAiSetting;
use Modules\GeneralLedger\Actions\OpenFiscalYearAction;
use Modules\OcrExtraction\Domain\Enums\OcrProviderType;
use Modules\OcrExtraction\Infrastructure\Models\DocumentExtractionJob;
use Modules\OcrExtraction\Infrastructure\Models\TenantOcrConfiguration;

require_once __DIR__.'/../../Unit/OcrExtraction/OcrFixtures.php';

/**
 * A company of the given country with the demo master data of the module: a vendor, an expense account, a VAT code and a
 * cost center.
 *
 * @return array{tenant: Tenant, vendor: string, account: string, account_b: string, vat: string, vat_reduced: string, cost_center: string}
 */
function ocrCompany(string $country = 'CH'): array
{
    $tenant = Tenant::create([
        'name' => 'Co '.Str::random(5),
        'legal_name' => 'Co Srl',
        'vat_number' => str_pad((string) random_int(1, 99999999999), 11, '0', STR_PAD_LEFT),
        'fiscal_code' => str_pad((string) random_int(1, 99999999999), 11, '0', STR_PAD_LEFT),
        'country' => $country,
        'default_currency' => $country === 'CH' ? 'CHF' : 'EUR',
    ]);

    // The chart of accounts is seeded with the company; the fiscal year 2026 is opened so documents of March can be posted.
    app(TenantContext::class)->runWithTenant($tenant, static fn (): mixed => app(OpenFiscalYearAction::class)->execute((string) $tenant->id, 2026));
    $accounts = DB::table('chart_of_accounts')->where('tenant_id', $tenant->id)->where('code', 'like', '6%')->orderBy('code')->limit(2)->pluck('id')->all();

    foreach (['6900', '6901'] as $position => $code) {
        if (! isset($accounts[$position])) {
            $accounts[$position] = (string) Str::uuid();
            DB::table('chart_of_accounts')->insert(['id' => $accounts[$position], 'tenant_id' => $tenant->id, 'code' => $code, 'name' => 'Materialaufwand '.$code, 'type' => 'expense', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    $ids = ['vendor' => (string) Str::uuid(), 'account' => (string) ($accounts[0] ?? Str::uuid()), 'account_b' => (string) ($accounts[1] ?? Str::uuid()), 'vat' => (string) Str::uuid(), 'vat_reduced' => (string) Str::uuid(), 'cost_center' => (string) Str::uuid()];
    $now = now();
    $suffix = Str::upper(Str::random(5));

    DB::table('contacts')->insert(['id' => $ids['vendor'], 'tenant_id' => $tenant->id, 'type' => 'supplier', 'company_name' => 'Muster AG', 'vat_number' => 'IT01234567890', 'uid_number' => 'CHE-123.456.789', 'iban' => 'CH93 0076 2011 6238 5295 7', 'country' => $country, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('vat_codes')->insert(['id' => $ids['vat'], 'tenant_id' => $tenant->id, 'code' => $country.'-STD-'.$suffix, 'country_iso' => $country, 'rate' => $country === 'CH' ? 8.1 : 22, 'tax_rate_percent' => $country === 'CH' ? 8.1 : 22, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('vat_codes')->insert(['id' => $ids['vat_reduced'], 'tenant_id' => $tenant->id, 'code' => $country.'-RED-'.$suffix, 'country_iso' => $country, 'rate' => $country === 'CH' ? 2.6 : 10, 'tax_rate_percent' => $country === 'CH' ? 2.6 : 10, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('cost_centers')->insert(['id' => $ids['cost_center'], 'tenant_id' => $tenant->id, 'code' => 'CC1', 'name' => 'Werkstatt', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);

    return ['tenant' => $tenant, ...$ids];
}

/**
 * Acts as a member of the company with the role.
 */
function ocrActAs(Tenant $tenant, string $role = 'accountant'): User
{
    $user = User::create(['name' => $role, 'email' => Str::uuid().'@example.com', 'password' => 'secret123', 'locale' => 'en']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    Sanctum::actingAs($user);
    test()->withHeader('X-Tenant-Id', (string) $tenant->id);

    return $user;
}

/**
 * Stores a file on the fake private disk and the DMS rows that describe it.
 */
function ocrDmsDocument(Tenant $tenant, string $contents, string $name, ?string $mime = null, ?string $folderId = null): DmsDocument
{
    Storage::fake('local');
    $path = 'dms/'.Str::uuid().'-'.$name;
    Storage::disk('local')->put($path, $contents);

    return app(TenantContext::class)->runWithTenant($tenant, function () use ($tenant, $path, $name, $mime, $contents, $folderId): DmsDocument {
        $document = DmsDocument::create(['tenant_id' => $tenant->id, 'title' => $name, 'folder_id' => $folderId, 'status' => 'active', 'current_version' => 1, 'access_roles' => [], 'metadata' => [], 'search_text' => '', 'legal_hold' => false]);
        DmsDocumentVersion::create(['tenant_id' => $tenant->id, 'document_id' => $document->id, 'version_number' => 1, 'disk' => 'local', 'path' => $path, 'original_name' => $name, 'mime_type' => $mime ?? 'application/octet-stream', 'size' => strlen($contents), 'sha256' => hash('sha256', $contents)]);

        return $document;
    });
}

/**
 * Makes the tenant use the engine, with its settings (an external engine also needs the consent of the tenant).
 *
 * @param  array<string, mixed>  $settings
 */
function ocrConfigure(Tenant $tenant, OcrProviderType $primary, array $settings = [], ?OcrProviderType $fallback = null, bool $consent = false): void
{
    app(TenantContext::class)->runWithTenant($tenant, function () use ($tenant, $primary, $settings, $fallback, $consent): void {
        TenantOcrConfiguration::create([
            'tenant_id' => $tenant->id,
            'primary_provider' => $primary,
            'fallback_provider' => $fallback,
            'provider_settings_encrypted' => [$primary->value => $settings],
            'dedicated_inbox_alias' => 'invoices@'.Str::lower(Str::random(6)).'.fatturafacile.ch',
        ]);

        if ($consent) {
            TenantAiSetting::create(['tenant_id' => $tenant->id, 'external_providers_enabled' => true]);
        }
    });
}

/**
 * The tenant consents to external AI providers.
 */
function ocrConsent(Tenant $tenant): void
{
    app(TenantContext::class)->runWithTenant($tenant, static fn (): TenantAiSetting => TenantAiSetting::create(['tenant_id' => $tenant->id, 'external_providers_enabled' => true]));
}

/**
 * A 1x1 PNG, enough for an engine that reads page images.
 */
function ocrPng(): string
{
    return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
}

/**
 * The answer of a Swiss invoice read by a vision model: lines and the three Swiss VAT rates.
 *
 * @return array<string, mixed>
 */
function ocrSwissModelAnswer(): array
{
    return [
        'vendor' => ['name' => 'Muster AG (OCR)', 'uid' => 'CHE-123.456.789', 'vat_number' => null, 'fiscal_code' => null, 'iban' => 'CH00 0000 0000 0000 0000 0', 'qr_iban' => null],
        'document' => ['number' => 'R-2026-17', 'issue_date' => '2026-03-05', 'due_date' => '2026-04-04', 'currency' => 'CHF', 'qr_reference' => null, 'qr_reference_type' => null],
        'order_references' => [],
        'totals' => ['net' => 1450.0, 'vat' => 106.1, 'gross' => 1556.1],
        'vat_breakdown' => [['rate' => 8.1, 'net' => 1200.0, 'vat' => 97.2], ['rate' => 2.6, 'net' => 50.0, 'vat' => 1.3], ['rate' => 3.8, 'net' => 200.0, 'vat' => 7.6]],
        'lines' => [
            ['description' => 'Montage Heizung', 'quantity' => 10, 'unit_price' => 120, 'net_amount' => 1200, 'vat_rate' => 8.1],
            ['description' => 'Verpflegung', 'quantity' => 1, 'unit_price' => 50, 'net_amount' => 50, 'vat_rate' => 2.6],
            ['description' => 'Hotelzimmer', 'quantity' => 2, 'unit_price' => 100, 'net_amount' => 200, 'vat_rate' => 3.8],
        ],
        'field_confidence' => ['vendor.name' => 91.0, 'header.document_number' => 94.0, 'header.issue_date' => 95.0, 'totals.net' => 88.0, 'lines[0].unit_price' => 62.0],
        'bounding_boxes' => ['header.document_number' => ['page' => 1, 'x' => 0.1, 'y' => 0.2, 'width' => 0.2, 'height' => 0.03], 'lines[0].unit_price' => ['page' => 1, 'x' => 0.7, 'y' => 0.45, 'width' => 0.1, 'height' => 0.02], 'bad' => ['x' => 'a']],
    ];
}

/**
 * Makes the QR-bill parser of the invoicing module answer with a decoded SPC 0200 payload, or find none.
 */
function ocrFakeQrBill(?ParsedQrBill $bill): void
{
    app()->instance(SwissQrBillParserInterface::class, new class($bill) implements SwissQrBillParserInterface
    {
        public function __construct(private readonly ?ParsedQrBill $bill) {}

        public function parseFile(string $bytes): ParsedQrBill
        {
            return $this->bill ?? throw QrBillParseException::because(QrBillParseException::QR_NOT_FOUND);
        }

        public function parsePayload(string $payload): ParsedQrBill
        {
            return $this->bill ?? throw QrBillParseException::because(QrBillParseException::INVALID_PAYLOAD);
        }
    });
}

function ocrSwissBill(): ParsedQrBill
{
    return new ParsedQrBill(
        payload: "SPC\r\n0200\r\n1\r\nCH4431999123000889012\r\n...",
        version: '0200',
        iban: 'CH4431999123000889012',
        isQrIban: true,
        creditor: ['name' => 'Muster AG', 'city' => 'Zürich', 'country' => 'CH'],
        amount: 1556.1,
        currency: 'CHF',
        debtor: null,
        referenceType: 'QRR',
        reference: '210000000003139471430009017',
        message: null,
        billingInformationRaw: null,
        billingInformation: ['invoice_number' => 'R-2026-17', 'invoice_date' => '2026-03-05', 'vat_number' => 'CHE-123.456.789'],
        valid: true,
        errors: [],
    );
}

/**
 * The FatturaPA of the fixtures with another number, so several invoices of the same vendor can be confirmed.
 */
function ocrInvoiceXml(string $number, string $date = '2026-03-05'): string
{
    return str_replace(['FT-17', '2026-03-05'], [$number, $date], ocrFatturaPaXml());
}

/**
 * Uploads a FatturaPA through the API and returns the job that is ready for review.
 *
 * @param  array<string, mixed>  $company
 */
function ocrReadyJob(object $test, array $company, string $number, string $date = '2026-03-05'): DocumentExtractionJob
{
    $document = ocrDmsDocument($company['tenant'], ocrInvoiceXml($number, $date), "{$number}.xml", 'application/xml');
    $test->postJson('/api/v1/ocr-inbox/documents', ['dms_document_id' => $document->id])->assertStatus(202)->assertJsonPath('data.status', 'ready_for_review');

    return DocumentExtractionJob::withoutGlobalScopes()->where('dms_document_id', $document->id)->firstOrFail();
}

/**
 * The reviewed payload of the workbench for a job: what was extracted, coded with the given accounts (or the AI suggestion).
 *
 * @param  array<string, mixed>  $company
 * @param  array<int, array<string, mixed>>  $lineOverrides  per line index
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function ocrReviewed(DocumentExtractionJob $job, array $company, array $lineOverrides = [], array $overrides = []): array
{
    $payload = $job->extracted_payload_json;
    $lines = [];

    foreach ($payload['lines'] as $index => $line) {
        $lines[] = [
            'description' => $line['description'],
            'quantity' => $line['quantity'],
            'unit_price' => $line['unit_price'],
            'net_amount' => $line['net_amount'],
            'vat_rate' => $line['vat_rate'],
            'gl_account_id' => $line['suggested_gl_account_id'] ?? $company['account'],
            'vat_code_id' => $line['suggested_vat_code_id'] ?? $company['vat'],
            'cost_center_id' => $line['suggested_cost_center_id'] ?? null,
            'source_line_index' => $index,
            ...($lineOverrides[$index] ?? []),
        ];
    }

    return [
        'vendor_id' => $company['vendor'],
        'header' => $payload['document'],
        'totals' => $payload['totals'],
        'lines' => $lines,
        ...$overrides,
    ];
}

function ocrConfirm(object $test, DocumentExtractionJob $job, array $reviewed): TestResponse
{
    return $test->postJson('/api/v1/ocr-inbox/'.$job->id.'/confirm', $reviewed);
}
