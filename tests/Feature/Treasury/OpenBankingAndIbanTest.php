<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\PassiveInvoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Treasury\Exceptions\OpenBankingUnavailableException;
use Modules\Treasury\Gateways\LocalOpenBankingGateway;
use Modules\Treasury\Models\OpenBankingConsent;
use Modules\Treasury\Models\OpenBankingPayment;
use Modules\Treasury\Services\IbanValidator;

uses(RefreshDatabase::class);

function bankActAs(Tenant $tenant, string $role = 'accountant'): User
{
    $user = User::create(['name' => $role, 'email' => Str::uuid().'@example.com', 'password' => 'secret123']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);
    app(TenantContext::class)->clear();
    Sanctum::actingAs($user);
    test()->withHeader('X-Tenant-Id', $tenant->id);

    return $user;
}

function paymentPayload(string $consentId, array $extra = []): array
{
    return [
        'consent_id' => $consentId,
        'creditor_iban' => 'DE89 3704 0044 0532 0130 00',
        'creditor_name' => 'Fornitore SA',
        'amount' => '120.50',
        'currency' => 'EUR',
        ...$extra,
    ];
}

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Alfa', 'legal_name' => 'Alfa SA']);
    bankActAs($this->tenant);
});

afterEach(fn () => app(TenantContext::class)->clear());

test('published example IBANs are valid, with or without spaces', function (string $iban): void {
    expect(app(IbanValidator::class)->isValid($iban))->toBeTrue();
})->with([
    'CH93 0076 2011 6238 5295 7',
    'DE89370400440532013000',
    'GB29NWBK60161331926819',
    'IT60X0542811101000000123456',
    'FR1420041010050500013M02606',
    'NL91ABNA0417164300',
    'NO9386011117947',
    'BE68539007547034',
    'MT84MALT011000012345MTLCAST001S',
    'it60 x054 2811 1010 0000 0123 456',
]);

test('a bad IBAN gets the reason', function (string $iban, string $reason): void {
    $result = app(IbanValidator::class)->check($iban);

    expect($result['is_valid'])->toBeFalse()->and($result['reason'])->toBe($reason);
})->with([
    ['DE89370400440532013001', 'invalid_checksum'],
    ['CH9300762011623852958', 'invalid_checksum'],
    ['DE8937040044053201300', 'invalid_length'],
    ['ZZ89370400440532013000', 'unknown_country'],
    ['not an iban', 'invalid_format'],
    ['', 'invalid_format'],
]);

test('every single digit changed in an IBAN is caught', function (): void {
    $iban = 'DE89370400440532013000';
    $validator = app(IbanValidator::class);

    foreach (range(4, strlen($iban) - 1) as $position) {
        $changed = $iban;
        $changed[$position] = (string) (((int) $iban[$position] + 1) % 10);

        expect($validator->isValid($changed))->toBeFalse();
    }
});

test('the check is available through the API to finance roles', function (): void {
    $this->postJson('/api/v1/treasury/iban/check', ['iban' => 'CH9300762011623852957'])->assertOk()->assertJsonPath('data.is_valid', true)->assertJsonPath('data.country', 'CH');

    bankActAs($this->tenant, 'member');
    $this->postJson('/api/v1/treasury/iban/check', ['iban' => 'CH9300762011623852957'])->assertForbidden();
});

test('by default open banking refuses, and nothing is stored', function (): void {
    $this->postJson('/api/v1/treasury/open-banking/consents', ['iban' => 'CH9300762011623852957'])->assertServiceUnavailable();

    expect(OpenBankingConsent::query()->count())->toBe(0);
});

test('a consent needs a valid IBAN', function (): void {
    config(['open_banking.driver' => 'local']);

    $this->postJson('/api/v1/treasury/open-banking/consents', ['iban' => 'CH9300762011623852958'])->assertUnprocessable();
});

test('the local driver covers consent, account information and payment, with the token encrypted', function (): void {
    config(['open_banking.driver' => 'local']);

    $response = $this->postJson('/api/v1/treasury/open-banking/consents', ['iban' => 'CH9300762011623852957'])->assertCreated();
    $consentId = $response->json('data.id');

    expect($response->json('data'))->not->toHaveKey('access_token');

    $raw = DB::table('open_banking_consents')->where('id', $consentId)->value('access_token');
    expect($raw)->not->toBe(OpenBankingConsent::query()->findOrFail($consentId)->access_token);

    $this->getJson("/api/v1/treasury/open-banking/consents/{$consentId}/account")->assertOk()->assertJsonPath('data.transactions', []);

    $paymentId = $this->postJson('/api/v1/treasury/open-banking/payments', paymentPayload($consentId))->assertCreated()
        ->assertJsonPath('data.creditor_iban', 'DE89370400440532013000')
        ->assertJsonPath('data.status', 'simulated')
        ->json('data.id');

    $this->getJson("/api/v1/treasury/open-banking/payments/{$paymentId}")->assertOk()->assertJsonPath('data.status', 'simulated');
});

test('a payment needs a valid IBAN and an active consent', function (): void {
    config(['open_banking.driver' => 'local']);
    $consentId = $this->postJson('/api/v1/treasury/open-banking/consents', ['iban' => 'CH9300762011623852957'])->json('data.id');

    $this->postJson('/api/v1/treasury/open-banking/payments', paymentPayload($consentId, ['creditor_iban' => 'DE89370400440532013001']))->assertUnprocessable();

    OpenBankingConsent::query()->findOrFail($consentId)->update(['expires_at' => now()->subDay()]);

    $this->postJson('/api/v1/treasury/open-banking/payments', paymentPayload($consentId))->assertConflict();
    $this->getJson("/api/v1/treasury/open-banking/consents/{$consentId}/account")->assertConflict();
});

test('a supplier invoice waiting for approval cannot go out by open banking', function (): void {
    config(['open_banking.driver' => 'local']);
    $consentId = $this->postJson('/api/v1/treasury/open-banking/consents', ['iban' => 'CH9300762011623852957'])->json('data.id');
    $supplier = Contact::factory()->create(['tenant_id' => $this->tenant->id]);
    $invoice = PassiveInvoice::withoutGlobalScopes()->create([
        'tenant_id' => $this->tenant->id,
        'supplier_id' => $supplier->id,
        'invoice_number' => 'P-1',
        'date' => '2026-01-01',
        'total_amount' => '120.50',
        'currency' => 'EUR',
        'document_type' => 'TD01',
    ]);
    DB::table('passive_invoices')->where('id', $invoice->id)->update(['approval_status' => 'pending']);

    $this->postJson('/api/v1/treasury/open-banking/payments', paymentPayload($consentId, ['passive_invoice_id' => $invoice->id]))->assertUnprocessable();
    expect(OpenBankingPayment::query()->count())->toBe(0);

    DB::table('passive_invoices')->where('id', $invoice->id)->update(['approval_status' => 'approved']);
    $this->postJson('/api/v1/treasury/open-banking/payments', paymentPayload($consentId, ['passive_invoice_id' => $invoice->id]))->assertCreated();
});

test('the simulator never runs in production', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    expect(fn () => (new LocalOpenBankingGateway)->requestConsent('CH9300762011623852957'))->toThrow(OpenBankingUnavailableException::class);
});

test('consents and payments stay in their tenant', function (): void {
    config(['open_banking.driver' => 'local']);
    $consentId = $this->postJson('/api/v1/treasury/open-banking/consents', ['iban' => 'CH9300762011623852957'])->json('data.id');
    $paymentId = $this->postJson('/api/v1/treasury/open-banking/payments', paymentPayload($consentId))->json('data.id');

    bankActAs(Tenant::create(['name' => 'Beta', 'legal_name' => 'Beta SA']), 'admin');

    $this->getJson("/api/v1/treasury/open-banking/consents/{$consentId}/account")->assertNotFound();
    $this->getJson("/api/v1/treasury/open-banking/payments/{$paymentId}")->assertNotFound();
    $this->postJson('/api/v1/treasury/open-banking/payments', paymentPayload($consentId))->assertUnprocessable();
});
