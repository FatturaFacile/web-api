<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Modules\ServiceManagement\Models\DepotRepairRmaOrder;
use Modules\ServiceManagement\Models\Loaner;
use Modules\ServiceManagement\Models\SerializedEquipmentPassport;
use Modules\ServiceManagement\Models\VendorWarrantyClaim;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->setTenant($this->tenant);
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);

    $this->passport = SerializedEquipmentPassport::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->rma = DepotRepairRmaOrder::factory()->create([
        'tenant_id' => $this->tenant->id,
        'equipment_passport_id' => $this->passport->id,
        'status' => 'in_diagnosis',
    ]);
});

test('vendor claim follows its state machine and records the credit note', function () {
    $claim = VendorWarrantyClaim::factory()->create(['tenant_id' => $this->tenant->id, 'rma_order_id' => $this->rma->id]);

    $this->patchJson("/api/v1/esm/vendor-claims/{$claim->id}", ['supplier_rma_reference' => 'SUP-RMA-77', 'claimed_amount' => 42.5])
        ->assertOk()
        ->assertJsonPath('data.supplier_rma_reference', 'SUP-RMA-77')
        ->assertJsonPath('data.claimed_amount', '42.50')
        ->assertJsonPath('data.rma_number', $this->rma->rma_number);

    $this->postJson("/api/v1/esm/vendor-claims/{$claim->id}/transition", ['to' => 'closed'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'invalid_transition');

    foreach (['submitted', 'acknowledged'] as $status) {
        $this->postJson("/api/v1/esm/vendor-claims/{$claim->id}/transition", ['to' => $status])
            ->assertOk()
            ->assertJsonPath('data.status', $status);
    }

    $this->postJson("/api/v1/esm/vendor-claims/{$claim->id}/transition", [
        'to' => 'approved_credit_note',
        'credit_note_reference' => 'NC-2026-15',
    ])->assertOk()
        ->assertJsonPath('data.status', 'approved_credit_note')
        ->assertJsonPath('data.credit_note_reference', 'NC-2026-15');

    expect($claim->fresh()->resolved_at)->not->toBeNull();

    $this->postJson("/api/v1/esm/vendor-claims/{$claim->id}/transition", ['to' => 'closed'])
        ->assertOk()
        ->assertJsonPath('data.status', 'closed');

    $this->getJson('/api/v1/esm/vendor-claims?status=closed')
        ->assertOk()
        ->assertJsonPath('meta.total', 1);

    $this->postJson("/api/v1/esm/vendor-claims/{$claim->id}/transition", ['to' => 'bogus'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_error');
});

test('a loaner unit can be issued once per RMA, returned and listed', function () {
    $response = $this->postJson("/api/v1/esm/rma-orders/{$this->rma->id}/loaners", [
        'loaner_serial_number' => 'LOAN-001',
        'register_type' => 'prestito_uso',
        'expected_return_at' => now()->addDays(14)->toIso8601String(),
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.register_type', 'prestito_uso')
        ->assertJsonPath('data.status', 'issued')
        ->assertJsonPath('data.ddt_causale', 'prestito_uso')
        ->assertJsonPath('data.is_overdue', false);

    $loanerId = $response->json('data.id');
    expect($this->rma->fresh()->loaner_id)->toBe($loanerId);

    $this->getJson("/api/v1/esm/rma-orders/{$this->rma->id}")
        ->assertOk()
        ->assertJsonPath('data.loaner.id', $loanerId);

    $this->postJson("/api/v1/esm/rma-orders/{$this->rma->id}/loaners", [
        'loaner_serial_number' => 'LOAN-002',
        'register_type' => 'prestito_uso',
    ])->assertStatus(409)->assertJsonPath('code', 'loaner_already_issued');

    $this->getJson('/api/v1/esm/loaners?register_type=prestito_uso&status=issued')
        ->assertOk()
        ->assertJsonPath('meta.total', 1);

    $this->postJson("/api/v1/esm/loaners/{$loanerId}/return")
        ->assertOk()
        ->assertJsonPath('data.status', 'returned');

    $this->postJson("/api/v1/esm/loaners/{$loanerId}/mark-lost")
        ->assertStatus(409)
        ->assertJsonPath('code', 'invalid_transition');
});

test('a loaner can be marked lost and loaner validation is enforced', function () {
    $this->postJson("/api/v1/esm/rma-orders/{$this->rma->id}/loaners", ['register_type' => 'prestito_uso'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['loaner_serial_number']);

    $loaner = Loaner::factory()->create(['tenant_id' => $this->tenant->id, 'rma_order_id' => $this->rma->id]);

    $this->postJson("/api/v1/esm/loaners/{$loaner->id}/mark-lost")
        ->assertOk()
        ->assertJsonPath('data.status', 'lost');
});

test('the overdue filter lists loaners still out after the repaired unit was returned', function () {
    $returned = DepotRepairRmaOrder::factory()->returned()->create([
        'tenant_id' => $this->tenant->id,
        'equipment_passport_id' => $this->passport->id,
    ]);
    $overdue = Loaner::factory()->create(['tenant_id' => $this->tenant->id, 'rma_order_id' => $returned->id]);
    Loaner::factory()->create(['tenant_id' => $this->tenant->id, 'rma_order_id' => $this->rma->id]);

    $this->getJson('/api/v1/esm/loaners?overdue=1')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $overdue->id)
        ->assertJsonPath('data.0.is_overdue', true);
});
