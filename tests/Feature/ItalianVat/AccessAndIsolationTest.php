<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

require_once __DIR__.'/ItalianVatTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    ivDeferForeignKeys();
    $this->tenant = ivTenant();
});

it('requires authentication', function (): void {
    $this->withHeader('X-Tenant-Id', (string) $this->tenant->id)->getJson('/api/v1/italian-vat/sections')->assertUnauthorized();
});

it('lets the accounting roles work and closes the module to the other members', function (string $role, int $status): void {
    ivActAs($this->tenant, ivUser($this->tenant, $role));

    $this->getJson('/api/v1/italian-vat/sections')->assertStatus($status);
})->with([
    'owner' => ['owner', 200],
    'admin' => ['admin', 200],
    'accountant' => ['accountant', 200],
    'read-only accountant' => ['accountant_readonly', 200],
    'sales' => ['sales', 403],
    'plain member' => ['member', 403],
]);

it('lets the read-only accountant read the registers but not change them', function (): void {
    ivActAs($this->tenant, ivUser($this->tenant, 'accountant'));
    ivRegister('VEN')->assertCreated();

    ivActAs($this->tenant, ivUser($this->tenant, 'accountant_readonly'));

    $this->getJson('/api/v1/italian-vat/registers/'.ivSectionId('VEN').'?from=2025-01-01&to=2025-12-31')->assertOk();
    $this->getJson('/api/v1/italian-vat/plafond/monitor?year=2025')->assertOk();
    $this->getJson('/api/v1/italian-vat/lipe')->assertOk();
    ivRegister('VEN')->assertForbidden();
    $this->postJson('/api/v1/italian-vat/registers/'.ivSectionId('VEN').'/prints', ['year' => 2025, 'period_to' => '2025-12-31'])->assertForbidden();
    $this->postJson('/api/v1/italian-vat/lipe', ['year' => 2025, 'quarter' => 1])->assertForbidden();
    $this->postJson('/api/v1/italian-vat/cu/2025/issue')->assertForbidden();
});

it('lets a member with the read grant run the simulations but not write', function (): void {
    $member = User::create(['name' => 'grant', 'email' => Str::uuid().'@example.com', 'password' => 'secret123', 'locale' => 'en']);
    $member->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'member', 'permissions' => json_encode(['italian_vat.read'])]);
    ivActAs($this->tenant, $member);

    $payload = ['entry_date' => '2025-03-01', 'gross_takings' => 100, 'purchase_basis' => [['rate' => 22, 'amount' => 10]]];

    $this->getJson('/api/v1/italian-vat/sections')->assertOk();
    $this->postJson('/api/v1/italian-vat/receipts/ventilation/preview', $payload)->assertOk();
    $this->postJson('/api/v1/italian-vat/receipts/ventilation', $payload)->assertForbidden();
    $this->postJson('/api/v1/italian-vat/plafond/check', ['contact_id' => (string) Str::uuid(), 'date' => '2025-03-01', 'amount' => 10])->assertOk();
});

it('keeps the registers, prints and lines of two tenants apart', function (): void {
    ivActAs($this->tenant);
    $entry = ivRegister('VEN', ['entry_date' => '2025-01-10'])->assertCreated()->json('data.id');
    $section = ivSectionId('VEN');
    $print = $this->postJson("/api/v1/italian-vat/registers/{$section}/prints", ['year' => 2025, 'period_to' => '2025-01-31'])->assertCreated()->json('data.id');

    $other = ivTenant('Other');
    ivActAs($other);

    // The other tenant has its own sectionals, numbered from 1, and cannot reach the ones of the first.
    expect(ivSectionId('VEN'))->not->toBe($section);
    ivRegister('VEN', ['entry_date' => '2025-01-10'])->assertCreated()->assertJsonPath('data.protocol_number', 1);
    ivRegister('VEN', ['section_id' => $section])->assertUnprocessable();
    $this->getJson("/api/v1/italian-vat/registers/{$section}?from=2025-01-01&to=2025-12-31")->assertNotFound();
    $this->postJson("/api/v1/italian-vat/registers/{$section}/prints", ['year' => 2025, 'period_to' => '2025-01-31'])->assertNotFound();
    $this->deleteJson("/api/v1/italian-vat/register-entries/{$entry}")->assertNotFound();
    $this->postJson("/api/v1/italian-vat/register-entries/{$entry}/reverse", ['entry_date' => '2025-02-01'])->assertNotFound();
    $this->getJson("/api/v1/italian-vat/register-prints/{$print}/verify")->assertNotFound();
    $this->get("/api/v1/italian-vat/register-prints/{$print}/pdf")->assertNotFound();
    $this->putJson("/api/v1/italian-vat/sections/{$section}", ['name' => 'Hijacked'])->assertNotFound();
    expect($this->getJson('/api/v1/italian-vat/register-prints')->assertOk()->json('data'))->toBe([]);
});

it('keeps the pro-rata of two tenants apart', function (): void {
    ivActAs($this->tenant);
    $this->putJson('/api/v1/italian-vat/pro-rata/2025', ['deductible_operations' => 700, 'total_operations' => 1000])->assertOk();

    ivActAs(ivTenant('Other'));

    $this->getJson('/api/v1/italian-vat/pro-rata/2025')->assertOk()
        ->assertJsonPath('data.rate', null)
        ->assertJsonPath('data.percentage_in_force', 100);
});
