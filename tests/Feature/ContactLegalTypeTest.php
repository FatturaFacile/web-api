<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'FatturaFacile Italy SRL', 'legal_name' => 'FatturaFacile Italy SRL', 'country' => 'IT', 'default_currency' => 'EUR']);
    $user = User::create(['name' => 'Leonardo', 'email' => 'leo@fatturafacile.com', 'password' => bcrypt('secret123'), 'locale' => 'en']);
    $user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    Sanctum::actingAs($user);
});

function legalTypeApi(string $locale = 'en')
{
    return test()->withHeaders(['X-Tenant-Id' => test()->tenant->id, 'X-Language-Code' => $locale]);
}

test('a company contact needs the company name', function (): void {
    legalTypeApi()->postJson('/api/v1/crm/contacts', ['type' => 'client', 'country' => 'CH', 'legal_type' => 'company'])
        ->assertUnprocessable()->assertJsonValidationErrors('company_name');

    legalTypeApi()->postJson('/api/v1/crm/contacts', ['type' => 'client', 'country' => 'CH', 'legal_type' => 'company', 'company_name' => 'Alpha SA'])
        ->assertCreated()->assertJsonPath('data.legal_type', 'company');
});

test('a person contact does not need the company name but needs the last name', function (): void {
    legalTypeApi()->postJson('/api/v1/crm/contacts', ['type' => 'client', 'country' => 'CH', 'legal_type' => 'person', 'first_name' => 'Anna'])
        ->assertUnprocessable()->assertJsonValidationErrors('last_name')->assertJsonMissingValidationErrors('company_name');

    legalTypeApi()->postJson('/api/v1/crm/contacts', ['type' => 'client', 'country' => 'CH', 'legal_type' => 'person', 'first_name' => 'Anna', 'last_name' => 'Rossi'])
        ->assertCreated()->assertJsonPath('data.legal_type', 'person')->assertJsonPath('data.company_name', null);
});

test('the contact kind must be company or person', function (): void {
    legalTypeApi()->postJson('/api/v1/crm/contacts', ['type' => 'client', 'country' => 'CH', 'legal_type' => 'robot', 'company_name' => 'Alpha SA'])
        ->assertUnprocessable()->assertJsonValidationErrors('legal_type');
});

test('contacts without a kind keep working', function (): void {
    legalTypeApi()->postJson('/api/v1/crm/contacts', ['type' => 'client', 'country' => 'CH', 'company_name' => 'Legacy SA'])
        ->assertCreated()->assertJsonPath('data.legal_type', null);
});

test('changing a contact to a company requires the company name', function (): void {
    $contact = Contact::factory()->create(['tenant_id' => $this->tenant->id, 'company_name' => null, 'legal_type' => 'person']);

    legalTypeApi()->putJson("/api/v1/crm/contacts/{$contact->id}", ['legal_type' => 'company'])
        ->assertUnprocessable()->assertJsonValidationErrors('company_name');
});

test('the error names the field in the language of the request', function (): void {
    $message = legalTypeApi('it')->postJson('/api/v1/crm/contacts', ['type' => 'client', 'country' => 'CH', 'legal_type' => 'company'])
        ->assertUnprocessable()->json('errors.company_name.0');

    expect($message)->toBe('Il campo ragione sociale è obbligatorio quando tipo di soggetto è company.');
});
