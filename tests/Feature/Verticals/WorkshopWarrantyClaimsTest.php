<?php

declare(strict_types=1);

use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2026-11-09 08:00:00');
    $this->tenant = verticalTenant();
    $this->customer = verticalContact($this->tenant);
    $this->supplier = verticalContact($this->tenant, 'Fornitore Ricambi SA');
    $this->vehicle = Vehicle::factory()->create(['tenant_id' => $this->tenant->id, 'client_id' => $this->customer->id, 'license_plate' => 'TI 123456']);
    $this->user = verticalMember($this->tenant, 'admin');
    verticalApi($this->user, $this->tenant);

    $this->folder = $this->postJson('/api/v1/workshop/folders', ['vehicle_id' => $this->vehicle->id, 'kind' => 'mechanics', 'currency' => 'CHF'])->assertCreated()->json('data.id');
    $this->line = $this->postJson("/api/v1/workshop/folders/{$this->folder}/lines", ['line_type' => 'part', 'description' => 'Pompa acqua', 'quantity' => '1', 'unit_price' => '200', 'discount_percentage' => '10', 'tax_rate' => '8.1'])->assertCreated()->json('data.id');
});

test('a claim defaults to the net total of its line and follows the status flow to the reimbursement', function (): void {
    $claim = $this->postJson('/api/v1/workshop/warranty-claims', ['folder_id' => $this->folder, 'folder_line_id' => $this->line, 'supplier_contact_id' => $this->supplier->id, 'description' => 'Pompa difettosa'])
        ->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.claimed_amount', '180.00')->assertJsonPath('data.currency', 'CHF');
    $id = $claim->json('data.id');
    expect($claim->json('data.claim_number'))->toStartWith('WAR-');

    $this->postJson("/api/v1/workshop/warranty-claims/{$id}/accept")->assertUnprocessable();
    $this->postJson("/api/v1/workshop/warranty-claims/{$id}/submit", ['supplier_reference' => 'RMA-77'])->assertOk()->assertJsonPath('data.status', 'submitted')->assertJsonPath('data.supplier_reference', 'RMA-77');
    $this->putJson("/api/v1/workshop/warranty-claims/{$id}", ['claimed_amount' => '10.00'])->assertUnprocessable();
    $this->postJson("/api/v1/workshop/warranty-claims/{$id}/reimburse", ['reimbursed_amount' => '100.00', 'reimbursed_on' => '2026-11-20'])->assertUnprocessable();
    $this->postJson("/api/v1/workshop/warranty-claims/{$id}/accept", ['note' => 'Ok'])->assertOk()->assertJsonPath('data.status', 'accepted');
    $this->postJson("/api/v1/workshop/warranty-claims/{$id}/reimburse", ['reimbursed_amount' => '180.01', 'reimbursed_on' => '2026-11-20'])->assertUnprocessable();
    $this->postJson("/api/v1/workshop/warranty-claims/{$id}/reimburse", ['reimbursed_amount' => '150.00', 'reimbursed_on' => '2026-11-20'])->assertOk()
        ->assertJsonPath('data.status', 'reimbursed')->assertJsonPath('data.reimbursed_amount', '150.00')->assertJsonPath('data.reimbursed_on', '2026-11-20');
    $this->postJson("/api/v1/workshop/warranty-claims/{$id}/reject")->assertUnprocessable();

    $this->getJson('/api/v1/workshop/warranty-claims?status=reimbursed')->assertOk()->assertJsonCount(1, 'data');
});

test('a claim needs a supplier and an amount before it is submitted, and a rejected claim is final', function (): void {
    $id = $this->postJson('/api/v1/workshop/warranty-claims', ['folder_id' => $this->folder, 'description' => 'Rumore'])->assertCreated()->assertJsonPath('data.claimed_amount', '0.00')->json('data.id');
    $this->postJson("/api/v1/workshop/warranty-claims/{$id}/submit")->assertUnprocessable()->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'supplier'));
    $this->putJson("/api/v1/workshop/warranty-claims/{$id}", ['supplier_contact_id' => $this->supplier->id])->assertOk();
    $this->postJson("/api/v1/workshop/warranty-claims/{$id}/submit")->assertUnprocessable()->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'amount'));
    $this->putJson("/api/v1/workshop/warranty-claims/{$id}", ['claimed_amount' => '75.00'])->assertOk();
    $this->postJson("/api/v1/workshop/warranty-claims/{$id}/submit")->assertOk();
    $this->postJson("/api/v1/workshop/warranty-claims/{$id}/reject", ['note' => 'Usura normale'])->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.decision_note', 'Usura normale');
    $this->postJson("/api/v1/workshop/warranty-claims/{$id}/accept")->assertUnprocessable();
});

test('a claim line must belong to the folder and the payload is validated', function (): void {
    $other = $this->postJson('/api/v1/workshop/folders', ['vehicle_id' => $this->vehicle->id, 'kind' => 'mechanics', 'currency' => 'CHF'])->assertCreated()->json('data.id');
    $this->postJson('/api/v1/workshop/warranty-claims', ['folder_id' => $other, 'folder_line_id' => $this->line, 'description' => 'x'])->assertUnprocessable();
    $this->postJson('/api/v1/workshop/warranty-claims', ['folder_id' => $this->folder])->assertUnprocessable()->assertJsonValidationErrors('description');
    $this->postJson('/api/v1/workshop/warranty-claims', ['folder_id' => $this->folder, 'description' => 'x', 'supplier_contact_id' => '0190f1a0-0000-7000-8000-000000000000'])->assertUnprocessable()->assertJsonValidationErrors('supplier_contact_id');
});

test('reimbursing is reserved to the billing roles and other companies see nothing', function (): void {
    $id = $this->postJson('/api/v1/workshop/warranty-claims', ['folder_id' => $this->folder, 'supplier_contact_id' => $this->supplier->id, 'description' => 'x', 'claimed_amount' => '50'])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/workshop/warranty-claims/{$id}/submit")->assertOk();
    $this->postJson("/api/v1/workshop/warranty-claims/{$id}/accept")->assertOk();

    verticalApi(verticalMember($this->tenant, 'technician'), $this->tenant);
    $this->postJson("/api/v1/workshop/warranty-claims/{$id}/reimburse", ['reimbursed_amount' => '50', 'reimbursed_on' => '2026-11-20'])->assertForbidden();

    $other = verticalTenant();
    verticalApi(verticalMember($other, 'admin'), $other);
    $this->getJson("/api/v1/workshop/warranty-claims/{$id}")->assertNotFound();
});
