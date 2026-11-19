<?php

declare(strict_types=1);

use App\Contracts\Fiscal\VatValidationServiceInterface;
use App\Exceptions\Fiscal\InvalidVatFormatException;
use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VatValidationLog;
use App\Support\Fiscal\EuVatFormat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
    $this->tenant = Tenant::create(['name' => 'FatturaFacile Italy SRL', 'legal_name' => 'FatturaFacile Italy SRL', 'country' => 'IT', 'default_currency' => 'EUR']);
    $this->user = User::create(['name' => 'Leonardo', 'email' => 'leo@fatturafacile.com', 'password' => bcrypt('secret123'), 'locale' => 'en']);
    $this->user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    Sanctum::actingAs($this->user);
});

function vies()
{
    return test()->withHeader('X-Tenant-Id', test()->tenant->id);
}

test('member state syntax is checked offline and Greece is EL', function (): void {
    expect(EuVatFormat::isValid('DE', '123456789'))->toBeTrue()
        ->and(EuVatFormat::isValid('DE', '123'))->toBeFalse()
        ->and(EuVatFormat::isValid('FR', 'AB123456789'))->toBeTrue()
        ->and(EuVatFormat::isValid('CH', '123456789'))->toBeFalse()
        ->and(EuVatFormat::split('GR 123456789'))->toBe(['EL', '123456789'])
        ->and(EuVatFormat::split('123456789', 'GR'))->toBe(['EL', '123456789']);
});

test('a formally invalid number fails immediately without any network call', function (): void {
    Http::fake();

    expect(fn () => app(VatValidationServiceInterface::class)->validate('DE123'))->toThrow(InvalidVatFormatException::class);

    vies()->postJson('/api/v1/fiscal/vies/validate', ['vat_number' => 'DE123'])->assertUnprocessable();

    Http::assertNothingSent();
    expect(VatValidationLog::count())->toBe(0);
});

test('a valid number returns the trader data and consultation id, is logged and stamped on the contact', function (): void {
    Http::fake(['*check-vat-number' => Http::response([
        'valid' => true, 'name' => 'ACME GMBH', 'address' => "HAUPTSTR 1\n10115 BERLIN", 'requestIdentifier' => 'WAPIAAAA123', 'userError' => 'VALID',
    ])]);
    $contact = Contact::create(['tenant_id' => $this->tenant->id, 'type' => 'client', 'country' => 'DE', 'company_name' => 'Acme GmbH', 'vat_number' => 'DE123456789']);

    vies()->postJson('/api/v1/fiscal/vies/validate', ['vat_number' => 'DE 123 456 789', 'contact_id' => $contact->id])
        ->assertOk()
        ->assertJsonPath('data.status', 'valid')
        ->assertJsonPath('data.country_code', 'DE')
        ->assertJsonPath('data.vat_number', '123456789')
        ->assertJsonPath('data.trader_name', 'ACME GMBH')
        ->assertJsonPath('data.request_identifier', 'WAPIAAAA123');

    $log = VatValidationLog::firstOrFail();
    expect($log->request_identifier)->toBe('WAPIAAAA123')->and($log->tenant_id)->toBe($this->tenant->id)->and($log->contact_id)->toBe($contact->id);
    expect($contact->refresh()->fiscal_verification_source)->toBe('vies')->and($contact->fiscal_verified_at)->not->toBeNull();
});

test('Greek numbers are sent to VIES with the EL prefix', function (): void {
    Http::fake(['*check-vat-number' => Http::response(['valid' => true, 'name' => 'ELLAS SA', 'requestIdentifier' => 'X1'])]);

    vies()->postJson('/api/v1/fiscal/vies/validate', ['vat_number' => '123456789', 'country_code' => 'GR'])
        ->assertOk()->assertJsonPath('data.country_code', 'EL');

    Http::assertSent(fn ($request) => $request['countryCode'] === 'EL' && $request['vatNumber'] === '123456789');
});

test('an inactive number is invalid', function (): void {
    Http::fake(['*check-vat-number' => Http::response(['valid' => false, 'userError' => 'INVALID'])]);

    vies()->postJson('/api/v1/fiscal/vies/validate', ['vat_number' => 'FR12345678901'])
        ->assertOk()->assertJsonPath('data.status', 'invalid')->assertJsonPath('data.is_valid', false);
});

test('a VIES outage answers unavailable, is not cached and does not touch the contact', function (string $kind): void {
    $contact = Contact::create(['tenant_id' => $this->tenant->id, 'type' => 'client', 'country' => 'DE', 'company_name' => 'Acme GmbH']);

    match ($kind) {
        'ms' => Http::fake(['*' => Http::response(['valid' => false, 'userError' => 'MS_UNAVAILABLE'])]),
        'service' => Http::fake(['*' => Http::response(['userError' => 'SERVICE_UNAVAILABLE'], 503)]),
        'timeout' => Http::fake(['*' => fn () => throw new ConnectionException('timeout')]),
    };

    vies()->postJson('/api/v1/fiscal/vies/validate', ['vat_number' => 'DE123456789', 'contact_id' => $contact->id])
        ->assertOk()->assertJsonPath('data.status', 'unavailable')->assertJsonPath('data.is_valid', null);

    expect($contact->refresh()->fiscal_verified_at)->toBeNull();
    expect(Cache::has('vies:DE123456789'))->toBeFalse();
})->with(['ms', 'service', 'timeout']);

test('definitive answers are cached so VIES is queried once', function (): void {
    Http::fake(['*check-vat-number' => Http::response(['valid' => true, 'name' => 'ACME GMBH', 'requestIdentifier' => 'R1'])]);

    vies()->postJson('/api/v1/fiscal/vies/validate', ['vat_number' => 'DE123456789'])->assertOk()->assertJsonPath('data.from_cache', false);
    vies()->postJson('/api/v1/fiscal/vies/validate', ['vat_number' => 'DE123456789'])->assertOk()->assertJsonPath('data.from_cache', true);

    Http::assertSentCount(1);
});

test('the audit log and contacts of another company are never visible', function (): void {
    $other = Tenant::create(['name' => 'Other SRL', 'legal_name' => 'Other SRL', 'country' => 'IT', 'default_currency' => 'EUR']);
    $foreign = Contact::create(['tenant_id' => $other->id, 'type' => 'client', 'country' => 'DE', 'company_name' => 'Foreign']);
    VatValidationLog::withoutGlobalScopes()->create([
        'tenant_id' => $other->id, 'country_code' => 'DE', 'vat_number' => '987654321', 'status' => 'valid', 'is_valid' => true, 'validated_at' => now(),
    ]);

    vies()->getJson('/api/v1/fiscal/vies/logs')->assertOk()->assertJsonCount(0, 'data');

    Http::fake();
    vies()->postJson('/api/v1/fiscal/vies/validate', ['vat_number' => 'DE123456789', 'contact_id' => $foreign->id])->assertNotFound();
});
