<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\SDI\Enums\FpaStatus;
use Modules\SDI\Models\FpaDocument;
use Modules\SDI\Models\SdiConfiguration;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Rossi Srl', 'legal_name' => 'Rossi Srl', 'vat_number' => '00811720580', 'fiscal_code' => '00811720580', 'country' => 'IT', 'default_currency' => 'EUR']);
    $user = User::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => bcrypt('secret123')]);
    $user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    Sanctum::actingAs($user);
    app(TenantContext::class)->setTenant($this->tenant);
    $this->headers = ['X-Tenant-ID' => (string) $this->tenant->id];
});

afterEach(fn () => app(TenantContext::class)->clear());

test('the cockpit lists envelopes with KPI counters', function (): void {
    FpaDocument::factory()->create(['tenant_id' => $this->tenant->id, 'status' => FpaStatus::Rejected]);
    FpaDocument::factory()->create(['tenant_id' => $this->tenant->id, 'status' => FpaStatus::Delivered]);

    $response = $this->withHeaders($this->headers)->getJson('/api/v1/fpa-documents')->assertOk();

    expect($response->json('data'))->toHaveCount(2)
        ->and($response->json('meta.kpi.rejected'))->toBe(1)
        ->and($response->json('meta.kpi.delivered'))->toBe(1);
});

test('sandbox simulation is refused until enabled and then applies a rejection', function (): void {
    Storage::fake('local');
    $document = FpaDocument::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->withHeaders($this->headers)->postJson("/api/v1/fpa-documents/{$document->id}/simulate", ['type' => 'NS'])->assertForbidden();

    SdiConfiguration::query()->create(['tenant_id' => $this->tenant->id, 'sandbox_simulator_enabled' => true]);

    $this->withHeaders($this->headers)->postJson("/api/v1/fpa-documents/{$document->id}/simulate", ['type' => 'NS', 'error_code' => '00423'])
        ->assertOk()->assertJsonPath('data.status', 'rejected');

    $this->withHeaders($this->headers)->postJson("/api/v1/fpa-documents/{$document->id}/resubmit")->assertCreated();
    $this->withHeaders($this->headers)->getJson("/api/v1/fpa-documents/{$document->id}")->assertOk()->assertJsonCount(1, 'data.notifications');
});

test('settings are saved without exposing credentials', function (): void {
    $this->withHeaders($this->headers)->putJson('/api/v1/settings/sdi', ['signature_mode' => 'remote_hsm_provider', 'default_codice_destinatario' => '0000000', 'auto_sign_on_send' => true])
        ->assertOk()->assertJsonPath('data.signature_mode', 'remote_hsm_provider')->assertJsonPath('data.has_certificate', false);
});
