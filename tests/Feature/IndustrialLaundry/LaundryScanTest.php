<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\IndustrialLaundry\Models\Garment;
use Modules\IndustrialLaundry\Models\GarmentEvent;
use Modules\IndustrialLaundry\Models\RollCage;
use Modules\IndustrialLaundry\Models\ScanSession;
use Modules\Invoicing\Models\SalesDeliveryNote;
use Modules\Invoicing\Models\SalesDeliveryNoteItem;

require_once __DIR__.'/IndustrialLaundrySupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    ilrSetUp($this);
});

it('receives a soiled roll cage: filters the reads, weighs it, soils the garments and draws up the pick-up note', function (): void {
    foreach ([1, 2, 3] as $number) {
        ilrGarment($this, $number);
    }

    $payload = ilrScanPayload([1, 2, 3], ['external_scan_id' => 'SCAN-0001']);
    $payload['reads'] = [
        ...$payload['reads'],
        ['epc' => ilrEpc(1), 'rssi' => -60.0],
        ['epc' => ilrEpc(4), 'rssi' => -58.0],
        ['epc' => 'NOT-AN-EPC'],
        ['epc' => ilrEpc(5), 'rssi' => -95.0],
    ];

    $response = $this->postJson('/api/v1/industrial-laundry/scans', $payload)->assertCreated();

    expect($response->json('data.net_weight_kg'))->toBe('80.000')
        ->and($response->json('data.gross_weight_kg'))->toBe('118.500')
        ->and($response->json('data.accepted_count'))->toBe(3)
        ->and($response->json('data.rejected_count'))->toBe(4)
        ->and($response->json('data.raw_reads_count'))->toBe(7)
        ->and($response->json('data.contact_id'))->toBe((string) $this->customer->id)
        ->and(collect($response->json('data.rejected_reads'))->pluck('reason')->sort()->values()->all())->toBe(['duplicate', 'invalid_epc', 'out_of_range', 'unknown_tag'])
        ->and($response->json('data.read_epc_tags_list.deduplicated'))->toHaveCount(4)
        ->and($response->json('data.accepted_epc_tags'))->toBe([ilrEpc(1), ilrEpc(2), ilrEpc(3)]);

    expect(Garment::query()->where('current_lifecycle_state', 'soiled_returned')->count())->toBe(3)
        ->and(Garment::query()->whereNotNull('last_soiled_at')->count())->toBe(3)
        ->and(Garment::query()->sum('wash_cycles_count'))->toEqual(0)
        ->and(GarmentEvent::query()->where('event_type', 'received_soiled')->count())->toBe(3)
        ->and((string) RollCage::query()->where('barcode', 'RC-0001')->firstOrFail()->tare_weight_kg)->toBe('38.500');

    $number = (string) $response->json('data.generated_ddt_number');
    $note = SalesDeliveryNote::query()->where('ddt_number', $number)->firstOrFail();

    expect($number)->not->toBe('')
        ->and($note->type)->toBe('return_from_customer')
        ->and((string) $note->contact_id)->toBe((string) $this->customer->id)
        ->and((string) $note->net_weight_kg)->toBe('80.00')
        ->and(SalesDeliveryNoteItem::query()->where('delivery_note_id', $note->id)->count())->toBe(1)
        ->and((string) SalesDeliveryNoteItem::query()->where('delivery_note_id', $note->id)->firstOrFail()->quantity)->toBe('3.0000');
});

it('delivers the clean linen, counts the wash cycle and sends the roll cage to the customer', function (): void {
    foreach ([1, 2, 3] as $number) {
        ilrGarment($this, $number, ['current_lifecycle_state' => 'soiled_returned', 'wash_cycles_count' => 4]);
    }

    $this->postJson('/api/v1/industrial-laundry/roll-cages', ['barcode' => 'RC-0002', 'tare_weight_kg' => '42', 'max_gross_weight_kg' => '450'])->assertCreated();

    $response = $this->postJson('/api/v1/industrial-laundry/scans', ilrScanPayload([1, 2, 3], [
        'direction' => 'outbound_clean', 'roll_cage_barcode' => 'RC-0002', 'gross_weight_kg' => '142', 'roll_cage_tare_weight_kg' => null,
    ]))->assertCreated();

    expect($response->json('data.net_weight_kg'))->toBe('100.000')
        ->and($response->json('data.accepted_count'))->toBe(3)
        ->and(Garment::query()->where('current_lifecycle_state', 'delivered_to_customer')->count())->toBe(3)
        ->and(Garment::query()->pluck('wash_cycles_count')->unique()->all())->toBe([5])
        ->and(Garment::query()->whereNotNull('last_delivered_at')->count())->toBe(3)
        ->and(GarmentEvent::query()->where('event_type', 'delivered_clean')->count())->toBe(3)
        ->and((string) RollCage::query()->where('barcode', 'RC-0002')->firstOrFail()->holder_contact_id)->toBe((string) $this->customer->id);

    $note = SalesDeliveryNote::query()->where('ddt_number', (string) $response->json('data.generated_ddt_number'))->firstOrFail();

    expect($note->type)->toBe('outbound_sale');

    // The same cage coming back soiled releases it from the customer.
    $this->postJson('/api/v1/industrial-laundry/scans', ilrScanPayload([1, 2, 3], ['roll_cage_barcode' => 'RC-0002', 'gross_weight_kg' => '150', 'roll_cage_tare_weight_kg' => null]))->assertCreated();

    expect(RollCage::query()->where('barcode', 'RC-0002')->firstOrFail()->holder_contact_id)->toBeNull();
});

it('does not count a wash for clean stock that was never soiled', function (): void {
    ilrGarment($this, 1, ['current_lifecycle_state' => 'clean_in_stock', 'wash_cycles_count' => 7, 'assigned_customer_id' => null]);

    $this->postJson('/api/v1/industrial-laundry/scans', ilrScanPayload([1], ['direction' => 'outbound_clean', 'contact_id' => (string) $this->customer->id]))->assertCreated()
        ->assertJsonPath('data.accepted_count', 1);

    $garment = Garment::query()->firstOrFail();

    expect($garment->wash_cycles_count)->toBe(7)
        ->and($garment->current_lifecycle_state->value)->toBe('delivered_to_customer')
        ->and((string) $garment->assigned_customer_id)->toBe((string) $this->customer->id);
});

it('stops a PPE garment that reached its certified wash limit, warns when one is close and routes the first to mending', function (): void {
    $limit = ['article_type' => 'ppe_safety_garment', 'max_certified_wash_cycles_limit' => 50, 'ppe_category' => 2, 'ppe_certification_standard' => 'EN ISO 20471', 'current_lifecycle_state' => 'soiled_returned'];
    ilrGarment($this, 1, $limit + ['wash_cycles_count' => 49]);
    ilrGarment($this, 2, $limit + ['wash_cycles_count' => 44]);
    ilrGarment($this, 3, ['current_lifecycle_state' => 'soiled_returned', 'wash_cycles_count' => 1]);

    $response = $this->postJson('/api/v1/industrial-laundry/scans', ilrScanPayload([1, 2, 3], ['direction' => 'outbound_clean']))->assertCreated();

    $alerts = collect($response->json('data.alerts'));

    expect($response->json('data.accepted_count'))->toBe(2)
        ->and(collect($response->json('data.rejected_reads'))->pluck('reason', 'epc')->all())->toBe([ilrEpc(1) => 'ppe_wash_limit_reached'])
        ->and($alerts->firstWhere('code', 'ppe_wash_limit_reached')['severity'])->toBe('blocking')
        ->and($alerts->firstWhere('code', 'ppe_wash_limit_near')['severity'])->toBe('warning');

    $blocked = Garment::query()->where('epc_uid_rfid_hex', ilrEpc(1))->firstOrFail();
    $near = Garment::query()->where('epc_uid_rfid_hex', ilrEpc(2))->firstOrFail();

    expect($blocked->current_lifecycle_state->value)->toBe('in_mending')
        ->and($blocked->is_reissue_blocked)->toBeTrue()
        ->and($blocked->wash_cycles_count)->toBe(50)
        ->and($blocked->mending_or_condemn_reason)->toBe('ppe_wash_limit_reached')
        ->and($near->current_lifecycle_state->value)->toBe('delivered_to_customer')
        ->and($near->wash_cycles_count)->toBe(45)
        ->and(GarmentEvent::query()->where('event_type', 'ppe_blocked')->count())->toBe(1);

    // Read again at the gate, the blocked garment is refused.
    $again = $this->postJson('/api/v1/industrial-laundry/scans', ilrScanPayload([1], ['direction' => 'outbound_clean']))->assertCreated();

    expect($again->json('data.accepted_count'))->toBe(0)
        ->and($again->json('data.rejected_reads.0.reason'))->toBe('in_mending')
        ->and($again->json('data.generated_ddt_number'))->toBeNull();
});

it('only warns for a category I PPE garment at its limit and delivers it', function (): void {
    ilrGarment($this, 1, [
        'article_type' => 'ppe_safety_garment', 'max_certified_wash_cycles_limit' => 50, 'ppe_category' => 1, 'current_lifecycle_state' => 'soiled_returned', 'wash_cycles_count' => 49,
    ]);

    $response = $this->postJson('/api/v1/industrial-laundry/scans', ilrScanPayload([1], ['direction' => 'outbound_clean']))->assertCreated();

    expect($response->json('data.accepted_count'))->toBe(1)
        ->and($response->json('data.alerts.0.severity'))->toBe('warning')
        ->and(Garment::query()->firstOrFail()->current_lifecycle_state->value)->toBe('delivered_to_customer');
});

it('processes a scan once however many times the reader sends it', function (): void {
    ilrGarment($this, 1);
    $payload = ilrScanPayload([1], ['external_scan_id' => 'SCAN-IDEMPOTENT']);

    $first = $this->postJson('/api/v1/industrial-laundry/scans', $payload)->assertCreated();
    $second = $this->postJson('/api/v1/industrial-laundry/scans', $payload)->assertCreated();

    expect($second->json('data.id'))->toBe($first->json('data.id'))
        ->and(ScanSession::query()->count())->toBe(1)
        ->and(GarmentEvent::query()->where('event_type', 'received_soiled')->count())->toBe(1)
        ->and(SalesDeliveryNote::query()->where('type', 'return_from_customer')->count())->toBe(1);
});

it('refuses a gross weight below the tare and an unknown cage that is weighed without a tare, and stores nothing', function (): void {
    $this->postJson('/api/v1/industrial-laundry/roll-cages', ['barcode' => 'RC-0001', 'tare_weight_kg' => '38.5'])->assertCreated();

    $this->postJson('/api/v1/industrial-laundry/scans', ilrScanPayload([], ['gross_weight_kg' => '30', 'roll_cage_tare_weight_kg' => null]))
        ->assertUnprocessable()->assertJsonPath('code', 'gross_below_tare');
    $this->postJson('/api/v1/industrial-laundry/scans', ilrScanPayload([], ['roll_cage_barcode' => 'RC-UNKNOWN', 'roll_cage_tare_weight_kg' => null]))
        ->assertUnprocessable()->assertJsonPath('code', 'roll_cage_unknown');

    expect(ScanSession::query()->count())->toBe(0)
        ->and(RollCage::query()->where('barcode', 'RC-UNKNOWN')->exists())->toBeFalse();
});

it('refuses a gross weight above the limit of the cage', function (): void {
    $this->postJson('/api/v1/industrial-laundry/roll-cages', ['barcode' => 'RC-0001', 'tare_weight_kg' => '38.5', 'max_gross_weight_kg' => '300'])->assertCreated();

    $this->postJson('/api/v1/industrial-laundry/scans', ilrScanPayload([], ['gross_weight_kg' => '350', 'roll_cage_tare_weight_kg' => null]))
        ->assertUnprocessable()->assertJsonPath('code', 'gross_above_limit');
});

it('refuses the garment of another customer when the cage is read for one customer', function (): void {
    ilrGarment($this, 1);
    ilrGarment($this, 2, ['assigned_customer_id' => $this->otherCustomer->id]);

    $response = $this->postJson('/api/v1/industrial-laundry/scans', ilrScanPayload([1, 2], ['contact_id' => (string) $this->customer->id]))->assertCreated();

    expect($response->json('data.accepted_count'))->toBe(1)
        ->and(collect($response->json('data.rejected_reads'))->pluck('reason', 'epc')->all())->toBe([ilrEpc(2) => 'customer_mismatch'])
        ->and(Garment::query()->where('epc_uid_rfid_hex', ilrEpc(2))->firstOrFail()->current_lifecycle_state->value)->toBe('delivered_to_customer');
});

it('does not touch a condemned garment nor read the garments of another company', function (): void {
    ilrGarment($this, 1, ['current_lifecycle_state' => 'condemned_rag']);

    $other = Tenant::create(['name' => 'Lavanderia Beta', 'legal_name' => 'Lavanderia Beta Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    treasuryInTenant($other, fn () => Garment::query()->create([
        'tenant_id' => $other->id, 'epc_uid_rfid_hex' => ilrEpc(2), 'article_type' => 'flat_linen', 'current_lifecycle_state' => 'delivered_to_customer', 'wash_cycles_count' => 0,
    ]));
    treasuryActAs($this, $this->actor, $this->tenant);

    $response = $this->postJson('/api/v1/industrial-laundry/scans', ilrScanPayload([1, 2], ['contact_id' => (string) $this->customer->id]))->assertCreated();

    expect($response->json('data.accepted_count'))->toBe(0)
        ->and(collect($response->json('data.rejected_reads'))->pluck('reason', 'epc')->all())->toEqualCanonicalizing([ilrEpc(1) => 'condemned', ilrEpc(2) => 'unknown_tag'])
        ->and($response->json('data.generated_ddt_number'))->toBeNull();
});

it('reports a roll cage with unknown garments as without a customer and draws up no note', function (): void {
    $response = $this->postJson('/api/v1/industrial-laundry/scans', ilrScanPayload([10]))->assertCreated();

    expect($response->json('data.contact_id'))->toBeNull()
        ->and($response->json('data.accepted_count'))->toBe(0)
        ->and($response->json('data.generated_ddt_number'))->toBeNull();
});

it('validates the scan payload', function (): void {
    $this->postJson('/api/v1/industrial-laundry/scans', ['gate_antenna_id' => 'GATE-01'])->assertUnprocessable();
    $this->postJson('/api/v1/industrial-laundry/scans', ilrScanPayload([1], ['direction' => 'sideways']))->assertUnprocessable();
    $this->postJson('/api/v1/industrial-laundry/scans', ilrScanPayload([1], ['reads' => [['epc' => ilrEpc(1), 'rssi' => -500]]]))->assertUnprocessable();
});

it('lists and reads the scan sessions of the company only', function (): void {
    ilrGarment($this, 1);
    $id = (string) $this->postJson('/api/v1/industrial-laundry/scans', ilrScanPayload([1]))->assertCreated()->json('data.id');

    $this->getJson('/api/v1/industrial-laundry/scans')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/industrial-laundry/scans?direction=outbound_clean')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/industrial-laundry/scans/{$id}")->assertOk()->assertJsonPath('data.roll_cage_barcode', 'RC-0001');

    $other = Tenant::create(['name' => 'Lavanderia Beta', 'legal_name' => 'Lavanderia Beta Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    treasuryActor($this, 'accountant', $other);

    $this->getJson("/api/v1/industrial-laundry/scans/{$id}")->assertNotFound();
    $this->getJson('/api/v1/industrial-laundry/scans')->assertOk()->assertJsonCount(0, 'data');
});

it('refuses the scans of a plain member', function (): void {
    treasuryActor($this, 'member');

    $this->postJson('/api/v1/industrial-laundry/scans', ilrScanPayload([1]))->assertForbidden();
});
