<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\IndustrialLaundry\Models\Garment;
use Modules\IndustrialLaundry\Models\GarmentEvent;

require_once __DIR__.'/IndustrialLaundrySupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    ilrSetUp($this);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function ilrPpe(object $test, int $number, int $cycles, array $overrides = []): Garment
{
    return ilrGarment($test, $number, $overrides + [
        'article_type' => 'ppe_safety_garment', 'max_certified_wash_cycles_limit' => 50, 'ppe_category' => 2, 'ppe_certification_standard' => 'EN ISO 20471',
        'wash_cycles_count' => $cycles, 'assigned_wearer_employee_name' => 'Marco Bianchi', 'assigned_locker_code' => 'A-0'.$number,
    ]);
}

it('registers a garment with a normalised EPC and refuses a duplicate or an invalid one', function (): void {
    $response = $this->postJson('/api/v1/industrial-laundry/garments', [
        'epc_uid_rfid_hex' => 'e280 1160-0000-0000-0000-0001', 'article_type' => 'ppe_safety_garment', 'max_certified_wash_cycles_limit' => 50, 'ppe_category' => 3,
        'ppe_certification_standard' => 'EN ISO 11612', 'assigned_customer_id' => (string) $this->customer->id, 'thermal_barcode' => 'TH-0001',
    ])->assertCreated();

    expect($response->json('data.epc_uid_rfid_hex'))->toBe('E28011600000000000000001')
        ->and($response->json('data.current_lifecycle_state'))->toBe('clean_in_stock')
        ->and($response->json('data.wash_cycles_count'))->toBe(0)
        ->and($response->json('data.remaining_washes'))->toBe(50);

    $this->postJson('/api/v1/industrial-laundry/garments', ['epc_uid_rfid_hex' => 'E28011600000000000000001', 'article_type' => 'flat_linen'])
        ->assertUnprocessable()->assertJsonPath('code', 'epc_taken');
    $this->postJson('/api/v1/industrial-laundry/garments', ['epc_uid_rfid_hex' => 'XYZ-1', 'article_type' => 'flat_linen'])
        ->assertUnprocessable()->assertJsonPath('code', 'epc_invalid');
    $this->postJson('/api/v1/industrial-laundry/garments', ['epc_uid_rfid_hex' => ilrEpc(5), 'article_type' => 'curtains'])->assertUnprocessable();

    $id = (string) $response->json('data.id');

    $this->putJson("/api/v1/industrial-laundry/garments/{$id}", ['epc_uid_rfid_hex' => ilrEpc(9)])->assertUnprocessable();
    $this->putJson("/api/v1/industrial-laundry/garments/{$id}", ['max_certified_wash_cycles_limit' => 60, 'description' => 'Giacca'])->assertOk()->assertJsonPath('data.remaining_washes', 60);

    $show = $this->getJson("/api/v1/industrial-laundry/garments/{$id}")->assertOk();

    expect($show->json('data.events.0.event_type'))->toBe('registered');
    $this->getJson('/api/v1/industrial-laundry/garments?article_type=ppe_safety_garment&q=e28011')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/industrial-laundry/garments?state=condemned_rag')->assertOk()->assertJsonCount(0, 'data');
});

it('lists the PPE garments near or at their certified wash limit, the ones past it first', function (): void {
    ilrPpe($this, 1, 50);
    ilrPpe($this, 2, 46);
    ilrPpe($this, 3, 20);
    ilrPpe($this, 4, 50, ['current_lifecycle_state' => 'condemned_rag']);
    ilrGarment($this, 5, ['wash_cycles_count' => 500]);

    $response = $this->getJson('/api/v1/industrial-laundry/ppe-alerts')->assertOk();

    expect(collect($response->json('data'))->map(fn (array $alert): string => $alert['epc_uid_rfid_hex'].':'.$alert['status'])->all())
        ->toBe([ilrEpc(1).':limit_reached', ilrEpc(2).':near_limit'])
        ->and($response->json('data.1.remaining_washes'))->toBe(4);

    $this->getJson('/api/v1/industrial-laundry/ppe-alerts?limit_reached_only=1')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/industrial-laundry/ppe-alerts?customer_id='.$this->otherCustomer->id)->assertOk()->assertJsonCount(0, 'data');
});

it('replaces a PPE garment at its limit with a new one that takes over the employee and the locker', function (): void {
    $old = ilrPpe($this, 1, 50);

    $response = $this->postJson("/api/v1/industrial-laundry/garments/{$old->id}/ppe-decision", ['action' => 'replace', 'replacement_epc' => 'e280 1160 0000 0000 0000 0777'])->assertOk();

    expect($response->json('data.epc_uid_rfid_hex'))->toBe('E28011600000000000000777')
        ->and($response->json('data.current_lifecycle_state'))->toBe('clean_in_stock')
        ->and($response->json('data.wash_cycles_count'))->toBe(0)
        ->and($response->json('data.max_certified_wash_cycles_limit'))->toBe(50)
        ->and($response->json('data.assigned_wearer_employee_name'))->toBe('Marco Bianchi')
        ->and($response->json('data.assigned_locker_code'))->toBe('A-01')
        ->and($response->json('data.ppe_certification_standard'))->toBe('EN ISO 20471');

    $old->refresh();

    expect($old->current_lifecycle_state->value)->toBe('condemned_rag')
        ->and($old->mending_or_condemn_reason)->toBe('ppe_replaced')
        ->and($old->assigned_wearer_employee_name)->toBeNull()
        ->and($old->condemned_at)->not->toBeNull()
        ->and(GarmentEvent::query()->where('event_type', 'replacement_approved')->count())->toBe(1);
});

it('needs the EPC of the replacement and refuses one that is already registered', function (): void {
    $old = ilrPpe($this, 1, 50);
    ilrGarment($this, 8);

    $this->postJson("/api/v1/industrial-laundry/garments/{$old->id}/ppe-decision", ['action' => 'replace'])->assertUnprocessable();
    $this->postJson("/api/v1/industrial-laundry/garments/{$old->id}/ppe-decision", ['action' => 'replace', 'replacement_epc' => ilrEpc(8)])
        ->assertUnprocessable()->assertJsonPath('code', 'epc_taken');
    $this->postJson("/api/v1/industrial-laundry/garments/{$old->id}/ppe-decision", ['action' => 'shred'])->assertUnprocessable();

    expect($old->refresh()->current_lifecycle_state->value)->toBe('delivered_to_customer');
});

it('sends a PPE garment to mending, keeps it blocked past its limit and releases it once the limit was raised', function (): void {
    $garment = ilrPpe($this, 1, 50);

    $this->postJson("/api/v1/industrial-laundry/garments/{$garment->id}/ppe-decision", ['action' => 'mend'])->assertOk()
        ->assertJsonPath('data.current_lifecycle_state', 'in_mending')->assertJsonPath('data.is_reissue_blocked', true);

    $this->postJson("/api/v1/industrial-laundry/garments/{$garment->id}/ppe-decision", ['action' => 'complete_mending'])
        ->assertUnprocessable()->assertJsonPath('code', 'ppe_limit_still_reached');

    $this->putJson("/api/v1/industrial-laundry/garments/{$garment->id}", ['max_certified_wash_cycles_limit' => 80])->assertOk();
    $this->postJson("/api/v1/industrial-laundry/garments/{$garment->id}/ppe-decision", ['action' => 'complete_mending'])->assertOk()
        ->assertJsonPath('data.current_lifecycle_state', 'clean_in_stock')->assertJsonPath('data.is_reissue_blocked', false);
});

it('condemns a PPE garment as a rag and then refuses any further decision', function (): void {
    $garment = ilrPpe($this, 1, 52);

    $this->postJson("/api/v1/industrial-laundry/garments/{$garment->id}/ppe-decision", ['action' => 'condemn', 'reason' => 'torn'])->assertOk()
        ->assertJsonPath('data.current_lifecycle_state', 'condemned_rag');

    expect($garment->refresh()->mending_or_condemn_reason)->toBe('torn');

    $this->postJson("/api/v1/industrial-laundry/garments/{$garment->id}/ppe-decision", ['action' => 'mend'])->assertUnprocessable()->assertJsonPath('code', 'garment_condemned');
    $this->putJson("/api/v1/industrial-laundry/garments/{$garment->id}", ['description' => 'x'])->assertUnprocessable()->assertJsonPath('code', 'garment_condemned');
});

it('has no decision for a garment that is not at its limit', function (): void {
    $garment = ilrPpe($this, 1, 10);

    $this->postJson("/api/v1/industrial-laundry/garments/{$garment->id}/ppe-decision", ['action' => 'condemn'])->assertUnprocessable()->assertJsonPath('code', 'no_ppe_alert');
});

it('assigns nominal garments to an employee and a locker and groups them by employee', function (): void {
    $first = ilrGarment($this, 1, ['article_type' => 'personal_workwear_uniform']);
    $second = ilrGarment($this, 2, ['article_type' => 'ppe_safety_garment', 'max_certified_wash_cycles_limit' => 50]);
    $linen = ilrGarment($this, 3);

    $this->postJson("/api/v1/industrial-laundry/garments/{$first->id}/locker", ['employee_name' => 'Marco Bianchi', 'locker_code' => 'A-01'])->assertOk()
        ->assertJsonPath('data.assigned_wearer_employee_name', 'Marco Bianchi')->assertJsonPath('data.assigned_locker_code', 'A-01');
    $this->postJson("/api/v1/industrial-laundry/garments/{$second->id}/locker", ['employee_name' => 'Marco Bianchi', 'locker_code' => 'A-01'])->assertOk();

    $groups = $this->getJson('/api/v1/industrial-laundry/lockers')->assertOk()->json('data');

    expect($groups)->toHaveCount(1)
        ->and($groups[0]['employee'])->toBe('Marco Bianchi')
        ->and($groups[0]['locker_code'])->toBe('A-01')
        ->and($groups[0]['garments'])->toHaveCount(2)
        ->and(GarmentEvent::query()->where('event_type', 'assigned')->count())->toBe(2);

    $this->getJson('/api/v1/industrial-laundry/lockers?q=marco')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/industrial-laundry/lockers?q=nobody')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/industrial-laundry/lockers?customer_id='.$this->otherCustomer->id)->assertOk()->assertJsonCount(0, 'data');

    // A locker belongs to one employee; linen is not worn by anyone; the employee and the locker go together.
    $this->postJson("/api/v1/industrial-laundry/garments/{$linen->id}/locker", ['employee_name' => 'Giulia Ferri', 'locker_code' => 'A-01'])->assertUnprocessable()->assertJsonPath('code', 'garment_not_nominal');
    $third = ilrGarment($this, 4, ['article_type' => 'personal_workwear_uniform']);
    $this->postJson("/api/v1/industrial-laundry/garments/{$third->id}/locker", ['employee_name' => 'Giulia Ferri', 'locker_code' => 'A-01'])->assertUnprocessable()->assertJsonPath('code', 'locker_taken');
    $this->postJson("/api/v1/industrial-laundry/garments/{$third->id}/locker", ['employee_name' => 'Giulia Ferri'])->assertUnprocessable();
});

it('releases a garment from its employee and locker', function (): void {
    $garment = ilrGarment($this, 1, ['article_type' => 'personal_workwear_uniform', 'assigned_wearer_employee_name' => 'Marco Bianchi', 'assigned_locker_code' => 'A-01']);

    $this->postJson("/api/v1/industrial-laundry/garments/{$garment->id}/locker", ['employee_name' => null, 'locker_code' => null])->assertOk()
        ->assertJsonPath('data.assigned_wearer_employee_name', null)->assertJsonPath('data.assigned_locker_code', null);

    $this->getJson('/api/v1/industrial-laundry/lockers')->assertOk()->assertJsonCount(0, 'data');
    expect(GarmentEvent::query()->where('event_type', 'released')->count())->toBe(1);
});

it('lists the garments at the customer past the threshold, the contract threshold winning over the default 60 days', function (): void {
    ilrGarment($this, 1, ['last_delivered_at' => now()->subDays(75)]);
    ilrGarment($this, 2, ['last_delivered_at' => now()->subDays(10)]);
    ilrGarment($this, 3, ['last_delivered_at' => now()->subDays(40), 'assigned_customer_id' => $this->otherCustomer->id]);
    ilrGarment($this, 4, ['last_delivered_at' => now()->subDays(200), 'current_lifecycle_state' => 'soiled_returned']);

    $this->postJson('/api/v1/industrial-laundry/par-contracts', [
        'code' => 'ILR-RSA', 'customer_id' => (string) $this->otherCustomer->id, 'customer_segment' => 'rsa', 'valid_from' => '2020-01-01', 'billing_model' => 'per_piece_washed',
        'unreturned_threshold_days' => 30, 'lines' => [['article_type' => 'flat_linen', 'circulating_stock_qty' => 10, 'unit_wash_price' => '1']],
    ])->assertCreated();

    $rows = $this->getJson('/api/v1/industrial-laundry/dormant-garments')->assertOk()->json('data');

    expect(collect($rows)->pluck('epc_uid_rfid_hex')->all())->toBe([ilrEpc(1), ilrEpc(3)])
        ->and($rows[0]['dormant_days'])->toBe(75)
        ->and($rows[0]['threshold_days'])->toBe(60)
        ->and($rows[0]['customer_name'])->toBe('Hotel Bellavista')
        ->and($rows[1]['dormant_days'])->toBe(40)
        ->and($rows[1]['threshold_days'])->toBe(30);

    $this->getJson('/api/v1/industrial-laundry/dormant-garments?days=90')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/industrial-laundry/dormant-garments?days=20')->assertOk()->assertJsonCount(3, 'data');
    $this->getJson('/api/v1/industrial-laundry/dormant-garments?customer_id='.$this->customer->id)->assertOk()->assertJsonCount(1, 'data');
});

it('shows the par level against the circulating stock of each customer by article type', function (): void {
    $this->postJson('/api/v1/industrial-laundry/par-contracts', [
        'code' => 'ILR-HOTEL', 'customer_id' => (string) $this->customer->id, 'customer_segment' => 'hotel', 'valid_from' => '2028-01-01', 'billing_model' => 'per_piece_washed',
        'lines' => [['article_type' => 'flat_linen', 'circulating_stock_qty' => 10, 'unit_wash_price' => '1']],
    ])->assertCreated();

    foreach ([1, 2, 3, 4] as $number) {
        ilrGarment($this, $number);
    }

    ilrGarment($this, 5, ['current_lifecycle_state' => 'soiled_returned']);
    ilrGarment($this, 6, ['current_lifecycle_state' => 'clean_in_stock']);
    ilrGarment($this, 7, ['current_lifecycle_state' => 'in_mending']);
    ilrGarment($this, 8, ['current_lifecycle_state' => 'condemned_rag']);
    ilrGarment($this, 9, ['article_type' => 'terry_towel']);

    $rows = $this->getJson('/api/v1/industrial-laundry/par-stock?with_preview=0')->assertOk()->json('data');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['customer_name'])->toBe('Hotel Bellavista')
        ->and($rows[0]['billing_preview'])->toBeNull()
        ->and($rows[0]['lines'])->toHaveCount(1)
        ->and($rows[0]['lines'][0])->toBe([
            'article_type' => 'flat_linen', 'par_level' => 10, 'circulating' => 7, 'at_customer' => 4, 'in_laundry' => 2, 'in_mending' => 1, 'gap' => 3,
        ])
        ->and($rows[0]['par_total'])->toBe(10)
        ->and($rows[0]['circulating_total'])->toBe(7);

    $withPreview = $this->getJson('/api/v1/industrial-laundry/par-stock')->assertOk()->json('data');

    expect($withPreview[0]['billing_preview'])->toHaveKeys(['wash_amount', 'minimum_top_up_amount', 'rental_amount', 'unreturned_amount', 'subtotal', 'tax_amount', 'total']);

    $this->getJson('/api/v1/industrial-laundry/par-stock?segment=clinic')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/industrial-laundry/par-stock?customer_id='.$this->otherCustomer->id)->assertOk()->assertJsonCount(0, 'data');
});

it('serves the enumerations, the wash programs and the customers of the company', function (): void {
    $data = $this->getJson('/api/v1/industrial-laundry/lookups')->assertOk()->json('data');

    expect($data['enums']['article_type'])->toBe(['flat_linen', 'terry_towel', 'hospital_scrub', 'personal_workwear_uniform', 'ppe_safety_garment'])
        ->and($data['enums']['lifecycle_state'])->toContain('condemned_rag')
        ->and($data['nominal_article_types'])->toBe(['personal_workwear_uniform', 'ppe_safety_garment'])
        ->and(collect($data['programs'])->pluck('code')->all())->toContain('THERMAL_71_3MIN', 'CHEMOTHERMAL_60_PAA')
        ->and($data['defaults']['dormant_days'])->toBe(60)
        ->and(collect($data['customers'])->pluck('name')->all())->toContain('Hotel Bellavista', 'RSA Villa Serena');
});

it('refuses the writes of a plain member and keeps the garments of a company away from the others', function (): void {
    $garment = ilrGarment($this, 1);

    $other = Tenant::create(['name' => 'Lavanderia Beta', 'legal_name' => 'Lavanderia Beta Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    treasuryActor($this, 'accountant', $other);

    $this->getJson("/api/v1/industrial-laundry/garments/{$garment->id}")->assertNotFound();
    $this->getJson('/api/v1/industrial-laundry/garments')->assertOk()->assertJsonCount(0, 'data');

    treasuryActor($this, 'member');

    $this->postJson('/api/v1/industrial-laundry/garments', ['epc_uid_rfid_hex' => ilrEpc(5), 'article_type' => 'flat_linen'])->assertForbidden();
});
