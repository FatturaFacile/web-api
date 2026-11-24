<?php

declare(strict_types=1);

use App\Models\ConstructionSite;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = verticalTenant();
    $this->customer = verticalContact($this->tenant);
    $this->user = verticalMember($this->tenant, 'admin');
    verticalApi($this->user, $this->tenant);
});

test('construction sites are created, changed and archived, and progress billings can use them', function (): void {
    $id = $this->postJson('/api/v1/construction/sites', [
        'client_id' => $this->customer->id, 'code' => 'CAN-001', 'name' => 'Residenza Lago', 'address' => 'Via Lago 3, Lugano',
        'sia_norm_reference' => 'SIA 118', 'start_date' => '2026-11-01', 'estimated_end_date' => '2027-06-30',
    ])->assertCreated()->assertJsonPath('data.status', 'planning')->json('data.id');

    expect((string) ConstructionSite::query()->findOrFail($id)->tenant_id)->toBe((string) $this->tenant->id);

    $this->postJson('/api/v1/construction/sites', ['client_id' => $this->customer->id, 'code' => 'CAN-001', 'name' => 'Doppio', 'address' => 'X'])
        ->assertUnprocessable()->assertJsonValidationErrors('code');
    $this->patchJson("/api/v1/construction/sites/{$id}", ['status' => 'active'])->assertOk()->assertJsonPath('data.status', 'active');
    $this->patchJson("/api/v1/construction/sites/{$id}", ['status' => 'demolished'])->assertUnprocessable();
    $this->getJson("/api/v1/construction/sites/{$id}")->assertOk()->assertJsonPath('data.client.id', $this->customer->id);

    $this->postJson('/api/v1/construction/sal', [
        'construction_site_id' => $id, 'sal_number' => 'SAL-1', 'period_start' => '2026-11-01', 'period_end' => '2026-11-30', 'gross_certified_amount' => 10000,
    ])->assertSuccessful();

    // A site with progress billings is completed, not deleted.
    $this->deleteJson("/api/v1/construction/sites/{$id}")->assertUnprocessable();

    $empty = $this->postJson('/api/v1/construction/sites', ['client_id' => $this->customer->id, 'code' => 'CAN-002', 'name' => 'Box', 'address' => 'Via Box'])->assertCreated()->json('data.id');
    $this->deleteJson("/api/v1/construction/sites/{$empty}")->assertNoContent();
    expect(ConstructionSite::withTrashed()->findOrFail($empty)->trashed())->toBeTrue();
});

test('construction sites of another company are invisible and customers of another company are refused', function (): void {
    $site = ConstructionSite::create(['tenant_id' => $this->tenant->id, 'client_id' => $this->customer->id, 'code' => 'CAN-X', 'name' => 'Privato', 'address' => 'Via 1']);

    $other = verticalTenant();
    $foreignCustomer = verticalContact($other, 'Altro SA');
    verticalApi(verticalMember($other, 'admin'), $other);
    app(TenantContext::class)->setTenant($other);

    $this->getJson("/api/v1/construction/sites/{$site->id}")->assertNotFound();
    $this->patchJson("/api/v1/construction/sites/{$site->id}", ['name' => 'Hijack'])->assertNotFound();

    verticalApi($this->user, $this->tenant);
    app(TenantContext::class)->setTenant($this->tenant);
    $this->postJson('/api/v1/construction/sites', ['client_id' => $foreignCustomer->id, 'code' => 'CAN-Y', 'name' => 'Y', 'address' => 'Y'])
        ->assertUnprocessable()->assertJsonValidationErrors('client_id');
});
