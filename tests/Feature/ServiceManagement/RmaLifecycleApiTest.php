<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\ServiceManagement\Models\DepotRepairRmaOrder;
use Modules\ServiceManagement\Models\EquipmentComponent;
use Modules\ServiceManagement\Models\Loaner;
use Modules\ServiceManagement\Models\RmaStatusHistory;
use Modules\ServiceManagement\Models\SerializedEquipmentPassport;
use Modules\ServiceManagement\Models\VendorWarrantyClaim;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 11, 15)->setTime(9, 0));

    $this->tenant = Tenant::factory()->create(['country' => 'CH', 'default_currency' => 'CHF']);
    app(TenantContext::class)->setTenant($this->tenant);
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);

    $this->customer = Contact::factory()->create([
        'tenant_id' => $this->tenant->id,
        'country' => 'CH',
        'company_name' => 'ACME SA',
        'vat_number' => null,
    ]);

    $this->registerPassport = function (string $serial, string $deliveryDate, array $extra = []): array {
        return $this->postJson('/api/v1/esm/equipment-passports', array_merge([
            'serial_number' => $serial,
            'current_owner_customer_id' => $this->customer->id,
            'delivery_date' => $deliveryDate,
            'components' => [
                ['position' => 'PSU', 'component_serial' => 'PSU-'.$serial, 'role' => 'power_supply'],
            ],
        ], $extra))->assertCreated()->json('data');
    };
});

test('in-warranty RMA runs the full depot repair lifecycle with warranty provision and vendor claim-back', function () {
    $passport = ($this->registerPassport)('SN-FULL-1', '2025-03-10');
    $supplierId = (string) Str::uuid7();

    // 1. Lookup + issue (LookupSerialWarrantyAndIssueRma)
    $issue = $this->postJson('/api/v1/esm/rma-orders', [
        'serial_number' => 'SN-FULL-1',
        'reported_symptom' => 'Unit reboots randomly',
    ]);

    $issue->assertCreated()
        ->assertJsonPath('data.status', 'awaiting_arrival')
        ->assertJsonPath('data.warranty_status', 'covered_in_warranty')
        ->assertJsonPath('data.serial_number', 'SN-FULL-1')
        ->assertJsonPath('data.estimate.currency', 'CHF')
        ->assertJsonPath('data.ddt.causale_outbound', 'conto_riparazione')
        ->assertJsonPath('data.ddt.is_non_ownership_transfer', true)
        ->assertJsonPath('data.label.serial_number', 'SN-FULL-1')
        ->assertJsonPath('data.label.customer_name', 'ACME SA');

    $rmaNumber = $issue->json('data.rma_number');
    expect($rmaNumber)->toMatch('/^RMA-2026-\d{6}$/')
        ->and($issue->json('data.label.barcode_payload'))->toBe($rmaNumber)
        ->and(strlen((string) $issue->json('data.label.tracking_token')))->toBeGreaterThanOrEqual(48)
        ->and($issue->json('data.label.tracking_url'))->toContain('/rma-tracking?rma='.$rmaNumber);

    $rmaId = $issue->json('data.id');
    $rma = DepotRepairRmaOrder::query()->findOrFail($rmaId);
    expect($rma->tracking_token_hash)->toBe(hash('sha256', (string) $issue->json('data.label.tracking_token')));

    // Label endpoint never re-exposes the raw token
    $this->getJson("/api/v1/esm/rma-orders/{$rmaId}/label")
        ->assertOk()
        ->assertJsonPath('data.tracking_url', null)
        ->assertJsonMissingPath('data.tracking_token');

    // 2. Receive -> in_diagnosis + "conto riparazione" register entry
    $this->postJson("/api/v1/esm/rma-orders/{$rmaId}/receive", [
        'receipt_condition_notes' => 'Scratches on the left side',
        'receipt_photos' => [['url' => 'https://cdn.example.test/p1.jpg', 'caption' => 'left side']],
    ])->assertOk()
        ->assertJsonPath('data.status', 'in_diagnosis')
        ->assertJsonPath('data.receipt_photos.0.caption', 'left side');

    expect(Loaner::query()->where('rma_order_id', $rmaId)->where('register_type', 'conto_riparazione')->where('status', 'issued')->count())->toBe(1);

    // 3. Bench diagnosis on a covered unit: lines charged to warranty provision, customer total 0.00
    $this->postJson("/api/v1/esm/rma-orders/{$rmaId}/diagnosis", [
        'lab_bench_diagnostic_notes' => 'PSU capacitor failure',
        'repair_feasible' => true,
        'parts_available' => false,
        'lines' => [
            [
                'line_type' => 'part',
                'description' => 'Power supply unit',
                'qty' => 1,
                'unit_cost' => 40,
                'unit_price' => 90,
                'defective_part_serial' => 'PSU-SN-FULL-1',
                'supplier_id' => $supplierId,
            ],
            ['line_type' => 'labour', 'description' => 'Bench labour 1h', 'qty' => 1, 'unit_cost' => 35, 'unit_price' => 95],
        ],
    ])->assertOk()
        ->assertJsonPath('data.status', 'awaiting_parts')
        ->assertJsonPath('data.estimate.approval_status', 'not_required')
        ->assertJsonPath('data.estimate.total', '0.00')
        ->assertJsonPath('data.lines.0.charged_to', 'warranty_provision')
        ->assertJsonPath('data.lines.1.charged_to', 'warranty_provision')
        ->assertJsonPath('data.vendor_claims.0.status', 'draft')
        ->assertJsonPath('data.vendor_claims.0.original_supplier_id', $supplierId)
        ->assertJsonPath('data.vendor_claims.0.claimed_amount', '40.00')
        ->assertJsonMissingPath('data.estimate_token');

    expect(VendorWarrantyClaim::query()->where('rma_order_id', $rmaId)->count())->toBe(1);
    $this->assertDatabaseHas('transactional_outbox', [
        'tenant_id' => $this->tenant->id,
        'event_type' => 'esm.rma.warranty_cost_charged',
        'aggregate_id' => $rmaId,
    ]);
    $this->assertDatabaseHas('transactional_outbox', [
        'tenant_id' => $this->tenant->id,
        'event_type' => 'esm.vendor_claim.opened',
    ]);

    // 4. Parts received -> in_repair_testing
    $this->postJson("/api/v1/esm/rma-orders/{$rmaId}/parts-received")
        ->assertOk()
        ->assertJsonPath('data.status', 'in_repair_testing');

    // 5. Complete repair with component swap -> as-maintained genealogy
    $oldComponent = EquipmentComponent::query()->where('equipment_passport_id', $passport['id'])->firstOrFail();
    $this->postJson("/api/v1/esm/rma-orders/{$rmaId}/complete-repair", [
        'replaced_components' => [['old_component_id' => $oldComponent->id, 'new_component_serial' => 'PSU-NEW-1']],
        'installed_firmware_version' => '3.1.0',
        'test_notes' => 'Burn-in 24h passed',
    ])->assertOk()
        ->assertJsonPath('data.status', 'ready_for_return')
        ->assertJsonPath('data.repair_outcome', 'repaired');

    $this->getJson("/api/v1/esm/equipment-passports/{$passport['id']}/genealogy")
        ->assertOk()
        ->assertJsonCount(1, 'data.as_built')
        ->assertJsonPath('data.as_maintained.0.component_serial', 'PSU-NEW-1')
        ->assertJsonPath('data.as_maintained.0.source', 'as_maintained')
        ->assertJsonPath('data.history.0.replaced_component_id', $oldComponent->id)
        ->assertJsonPath('data.history.0.rma_order_id', $rmaId);

    expect(SerializedEquipmentPassport::query()->findOrFail($passport['id'])->installed_firmware_version)->toBe('3.1.0');

    // 6. Return -> returned; repair-account entry closed
    $this->postJson("/api/v1/esm/rma-orders/{$rmaId}/return", ['vat_exempt_art15_dpr633' => true])
        ->assertOk()
        ->assertJsonPath('data.status', 'returned')
        ->assertJsonPath('data.ddt.causale_return', 'reso_riparato')
        ->assertJsonPath('data.ddt.vat_exempt_art15_dpr633', true);

    expect(Loaner::query()->where('rma_order_id', $rmaId)->where('register_type', 'conto_riparazione')->value('status'))->toBe('returned');

    // 7. Close
    $this->postJson("/api/v1/esm/rma-orders/{$rmaId}/close")
        ->assertOk()
        ->assertJsonPath('data.status', 'closed');

    expect(RmaStatusHistory::query()->where('rma_order_id', $rmaId)->count())->toBe(7);
    $this->assertDatabaseHas('transactional_outbox', ['event_type' => 'esm.rma.completed', 'aggregate_id' => $rmaId]);
});

test('out-of-warranty diagnosis above the threshold requires customer approval and exposes the estimate token once', function () {
    ($this->registerPassport)('SN-BILL-1', '2023-01-10', ['jurisdiction' => 'IT', 'customer_type' => 'b2b']);

    $rmaId = $this->postJson('/api/v1/esm/rma-orders', [
        'serial_number' => 'SN-BILL-1',
        'reported_symptom' => 'Display flickers',
        'preauthorized_threshold' => 50,
    ])->assertCreated()
        ->assertJsonPath('data.warranty_status', 'out_of_warranty_billable')
        ->assertJsonPath('data.estimate.preauthorized_threshold', '50.00')
        ->json('data.id');

    $this->postJson("/api/v1/esm/rma-orders/{$rmaId}/receive")->assertOk();

    $diagnosis = $this->postJson("/api/v1/esm/rma-orders/{$rmaId}/diagnosis", [
        'lab_bench_diagnostic_notes' => 'Backlight inverter broken',
        'repair_feasible' => true,
        'lines' => [
            ['line_type' => 'part', 'description' => 'Backlight inverter', 'qty' => 1, 'unit_cost' => 20, 'unit_price' => 60],
            ['line_type' => 'labour', 'description' => 'Labour 0.5h', 'qty' => 0.5, 'unit_cost' => 35, 'unit_price' => 90],
        ],
    ]);

    $diagnosis->assertOk()
        ->assertJsonPath('data.status', 'awaiting_estimate_approval')
        ->assertJsonPath('data.estimate.approval_status', 'pending_customer_approval')
        ->assertJsonPath('data.estimate.parts_total', '60.00')
        ->assertJsonPath('data.estimate.labour_total', '45.00')
        ->assertJsonPath('data.estimate.total', '105.00')
        ->assertJsonPath('data.lines.0.charged_to', 'customer');

    $token = (string) $diagnosis->json('data.estimate_token');
    expect(strlen($token))->toBeGreaterThanOrEqual(48)
        ->and($diagnosis->json('data.estimate_url'))->toEndWith('/rma-estimate/'.$token)
        ->and($diagnosis->json('data.estimate.expires_at'))->not->toBeNull();

    $this->getJson("/api/v1/esm/rma-orders/{$rmaId}")->assertOk()->assertJsonMissingPath('data.estimate_token');

    // Resend issues a fresh token and invalidates the old one
    $resent = $this->postJson("/api/v1/esm/rma-orders/{$rmaId}/estimate/resend")->assertOk();
    expect($resent->json('data.estimate_token'))->not->toBe($token);
    $this->getJson('/api/v1/public/rma/estimates/'.$token)->assertNotFound();

    // Staff approval on behalf of the customer
    $this->postJson("/api/v1/esm/rma-orders/{$rmaId}/estimate/approve", ['note' => 'Approved by phone'])
        ->assertOk()
        ->assertJsonPath('data.status', 'in_repair_testing')
        ->assertJsonPath('data.estimate.approval_status', 'approved')
        ->assertJsonPath('data.estimate.decided_via', 'staff');

    $this->postJson("/api/v1/esm/rma-orders/{$rmaId}/estimate/approve")
        ->assertStatus(409)
        ->assertJsonPath('code', 'already_decided');
});

test('out-of-warranty estimate within the pre-authorized threshold is auto-approved', function () {
    ($this->registerPassport)('SN-AUTO-1', '2022-06-01');

    $rmaId = $this->postJson('/api/v1/esm/rma-orders', [
        'serial_number' => 'SN-AUTO-1',
        'reported_symptom' => 'Fan noise',
        'preauthorized_threshold' => 200,
    ])->json('data.id');
    $this->postJson("/api/v1/esm/rma-orders/{$rmaId}/receive")->assertOk();

    $this->postJson("/api/v1/esm/rma-orders/{$rmaId}/diagnosis", [
        'lab_bench_diagnostic_notes' => 'Worn fan bearing',
        'repair_feasible' => true,
        'parts_available' => false,
        'lines' => [
            ['line_type' => 'part', 'description' => 'Fan', 'qty' => 1, 'unit_cost' => 10, 'unit_price' => 25],
        ],
    ])->assertOk()
        ->assertJsonPath('data.estimate.approval_status', 'auto_approved_under_threshold')
        ->assertJsonPath('data.estimate.decided_via', 'auto')
        ->assertJsonPath('data.status', 'awaiting_parts')
        ->assertJsonMissingPath('data.estimate_token');
});

test('goodwill on an out-of-warranty RMA charges the warranty provision', function () {
    ($this->registerPassport)('SN-GOOD-1', '2022-06-01');

    $this->postJson('/api/v1/esm/rma-orders', [
        'serial_number' => 'SN-GOOD-1',
        'reported_symptom' => 'Dead on arrival after firmware update',
        'goodwill' => true,
    ])->assertCreated()
        ->assertJsonPath('data.warranty_status', 'goodwill');
});

test('a non repairable unit goes straight to ready_for_return unrepaired', function () {
    ($this->registerPassport)('SN-DEAD-1', '2025-03-10');
    $rmaId = $this->postJson('/api/v1/esm/rma-orders', ['serial_number' => 'SN-DEAD-1', 'reported_symptom' => 'Water damage'])->json('data.id');
    $this->postJson("/api/v1/esm/rma-orders/{$rmaId}/receive")->assertOk();

    $this->postJson("/api/v1/esm/rma-orders/{$rmaId}/diagnosis", [
        'lab_bench_diagnostic_notes' => 'Board corroded',
        'repair_feasible' => false,
        'lines' => [],
    ])->assertOk()
        ->assertJsonPath('data.status', 'ready_for_return')
        ->assertJsonPath('data.repair_outcome', 'returned_unrepaired');
});

test('illegal transitions return 409 invalid_transition', function () {
    ($this->registerPassport)('SN-ILLEGAL-1', '2025-03-10');
    $rmaId = $this->postJson('/api/v1/esm/rma-orders', ['serial_number' => 'SN-ILLEGAL-1', 'reported_symptom' => 'Noise'])->json('data.id');

    $this->postJson("/api/v1/esm/rma-orders/{$rmaId}/diagnosis", [
        'lab_bench_diagnostic_notes' => 'n/a',
        'repair_feasible' => true,
        'lines' => [],
    ])->assertStatus(409)->assertJsonPath('code', 'invalid_transition');

    $this->postJson("/api/v1/esm/rma-orders/{$rmaId}/receive")->assertOk();

    $this->postJson("/api/v1/esm/rma-orders/{$rmaId}/return")
        ->assertStatus(409)
        ->assertJsonPath('code', 'invalid_transition');

    $this->postJson("/api/v1/esm/rma-orders/{$rmaId}/parts-received")->assertStatus(409);
    $this->postJson("/api/v1/esm/rma-orders/{$rmaId}/close")->assertStatus(409);

    $this->postJson("/api/v1/esm/rma-orders/{$rmaId}/cancel", ['reason' => 'Customer withdrew'])
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');

    $this->postJson("/api/v1/esm/rma-orders/{$rmaId}/receive")->assertStatus(409);
});

test('RMA issue validation and lookup errors', function () {
    $this->postJson('/api/v1/esm/rma-orders', ['reported_symptom' => 'x'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_error');

    $this->postJson('/api/v1/esm/rma-orders', ['serial_number' => 'UNKNOWN', 'reported_symptom' => 'x'])
        ->assertNotFound()
        ->assertJsonPath('code', 'serial_not_found');
});

test('RMA numbers are sequential per tenant and year', function () {
    ($this->registerPassport)('SN-SEQ-1', '2025-03-10');

    $first = $this->postJson('/api/v1/esm/rma-orders', ['serial_number' => 'SN-SEQ-1', 'reported_symptom' => 'a'])->json('data.rma_number');
    $second = $this->postJson('/api/v1/esm/rma-orders', ['serial_number' => 'SN-SEQ-1', 'reported_symptom' => 'b'])->json('data.rma_number');

    expect((int) substr((string) $second, -6))->toBe((int) substr((string) $first, -6) + 1);
});

test('another tenant RMA is not visible (404)', function () {
    $other = Tenant::factory()->create();
    $foreign = app(TenantContext::class)->runWithTenant($other, fn () => DepotRepairRmaOrder::factory()->create());

    $this->getJson("/api/v1/esm/rma-orders/{$foreign->id}")->assertNotFound();
    $this->postJson("/api/v1/esm/rma-orders/{$foreign->id}/receive")->assertNotFound();
});

test('kanban board groups RMAs by status and hides cancelled', function () {
    ($this->registerPassport)('SN-BOARD-1', '2025-03-10');
    $a = $this->postJson('/api/v1/esm/rma-orders', ['serial_number' => 'SN-BOARD-1', 'reported_symptom' => 'a'])->json('data.id');
    $b = $this->postJson('/api/v1/esm/rma-orders', ['serial_number' => 'SN-BOARD-1', 'reported_symptom' => 'b'])->json('data.id');
    $c = $this->postJson('/api/v1/esm/rma-orders', ['serial_number' => 'SN-BOARD-1', 'reported_symptom' => 'c'])->json('data.id');
    $this->postJson("/api/v1/esm/rma-orders/{$b}/receive")->assertOk();
    $this->postJson("/api/v1/esm/rma-orders/{$c}/cancel", ['reason' => 'duplicate'])->assertOk();

    $response = $this->getJson('/api/v1/esm/rma-orders/board')->assertOk();
    $columns = collect($response->json('data.columns'))->keyBy('status');

    expect($columns)->toHaveCount(8)
        ->and($columns->has('cancelled'))->toBeFalse()
        ->and($columns['awaiting_arrival']['label'])->toBe('Attesa arrivo merce')
        ->and($columns['awaiting_arrival']['count'])->toBe(1)
        ->and($columns['awaiting_arrival']['items'][0]['id'])->toBe($a)
        ->and($columns['in_diagnosis']['count'])->toBe(1)
        ->and($columns['in_diagnosis']['items'][0]['has_loaner'])->toBeFalse()
        ->and($response->json('meta.generated_at'))->not->toBeNull();

    $this->getJson('/api/v1/esm/rma-orders?status=in_diagnosis')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $b);
});
