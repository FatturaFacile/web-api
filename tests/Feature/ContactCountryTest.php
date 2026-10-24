<?php

declare(strict_types=1);

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

function contactApi()
{
    return test()->withHeader('X-Tenant-ID', test()->tenant->id);
}

test('contacts accept any ISO alpha-2 country code', function (): void {
    foreach (['IT', 'CH', 'DE', 'FR'] as $country) {
        contactApi()->postJson('/api/v1/crm/contacts', ['type' => 'client', 'country' => $country, 'company_name' => "Customer {$country}"])
            ->assertCreated()->assertJsonPath('data.country', $country);
    }

    expect(contactApi()->getJson('/api/v1/crm/contacts?country=de')->json('data.data'))->toHaveCount(1);
});

test('country codes must be two uppercase letters', function (): void {
    foreach (['de', 'DEU', 'D1', ''] as $invalid) {
        contactApi()->postJson('/api/v1/crm/contacts', ['type' => 'client', 'country' => $invalid, 'company_name' => 'Invalid'])
            ->assertUnprocessable()->assertJsonValidationErrors('country');
    }
});
