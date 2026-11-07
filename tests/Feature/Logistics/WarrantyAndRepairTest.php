<?php

declare(strict_types=1);

use App\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inventory\Models\StockSerialNumber;

require_once __DIR__.'/fixtures.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = logisticsTenant();
    logisticsUser($this->tenant);
    $this->contact = Contact::create(['company_name' => 'Cliente']);
    $this->item = logisticsItem(['tracking_type' => 'serial', 'name' => 'Laptop']);
    $this->serial = StockSerialNumber::create(['stock_item_id' => $this->item->id, 'serial_number' => 'SN-100', 'status' => 'delivered']);
});

function warrantyPayload(array $overrides = []): array
{
    return $overrides + ['contact_id' => test()->contact->id, 'purchase_date' => now()->subMonth()->toDateString(), 'purchase_value' => '1299.00', 'purchase_currency' => 'CHF', 'warranty_start_date' => now()->subMonth()->toDateString(), 'warranty_end_date' => now()->addYear()->toDateString()];
}

function openRepair(): string
{
    test()->putJson('/api/v1/inventory/serials/'.test()->serial->id.'/warranty', warrantyPayload())->assertOk();

    return test()->postJson('/api/v1/inventory/repair-orders', ['stock_serial_number_id' => test()->serial->id, 'fault_description' => 'Non si accende'])->assertCreated()->json('id');
}

test('a warranty is recorded on the serial and looked up by number', function (): void {
    $this->getJson('/api/v1/inventory/warranties/lookup?serial_number=SN-100')->assertOk()->assertJsonPath('warranty.status', 'none');

    $this->putJson("/api/v1/inventory/serials/{$this->serial->id}/warranty", warrantyPayload())
        ->assertOk()
        ->assertJsonPath('warranty.status', 'active')
        ->assertJsonPath('purchase_value', '1299.00')
        ->assertJsonPath('item_name', 'Laptop');

    $this->getJson('/api/v1/inventory/warranties/lookup?serial_number=NOPE')->assertNotFound();
});

test('warranty dates decide the status and no period is assumed', function (): void {
    $this->putJson("/api/v1/inventory/serials/{$this->serial->id}/warranty", warrantyPayload(['warranty_start_date' => '2020-01-01', 'warranty_end_date' => '2021-01-01']))
        ->assertOk()
        ->assertJsonPath('warranty.status', 'expired');
    $this->putJson("/api/v1/inventory/serials/{$this->serial->id}/warranty", warrantyPayload(['warranty_start_date' => now()->addDay()->toDateString()]))
        ->assertOk()
        ->assertJsonPath('warranty.status', 'not_started');

    $payload = warrantyPayload();
    unset($payload['warranty_end_date']);
    $this->putJson("/api/v1/inventory/serials/{$this->serial->id}/warranty", $payload)->assertStatus(422);
});

test('a repair links serial, customer and purchase value and keeps them', function (): void {
    $id = openRepair();

    $this->getJson("/api/v1/inventory/repair-orders/{$id}")
        ->assertOk()
        ->assertJsonPath('contact_id', $this->contact->id)
        ->assertJsonPath('purchase_value', '1299.00')
        ->assertJsonPath('is_under_warranty', true)
        ->assertJsonPath('status', 'received')
        ->assertJsonPath('serial.serial_number', 'SN-100');

    expect($this->serial->fresh()->status)->toBe('in_repair');

    $this->serial->update(['purchase_value' => '1.00', 'warranty_end_date' => '2020-01-01']);

    $this->getJson("/api/v1/inventory/repair-orders/{$id}")->assertJsonPath('purchase_value', '1299.00')->assertJsonPath('is_under_warranty', true);
    $this->getJson('/api/v1/inventory/warranties/lookup?serial_number=SN-100')->assertJsonPath('open_repairs', 1);
});

test('a repair needs a customer and an expired warranty is not covered', function (): void {
    $this->postJson('/api/v1/inventory/repair-orders', ['stock_serial_number_id' => $this->serial->id, 'fault_description' => 'X'])->assertStatus(422);

    $this->putJson("/api/v1/inventory/serials/{$this->serial->id}/warranty", warrantyPayload(['warranty_start_date' => '2020-01-01', 'warranty_end_date' => '2021-01-01']))->assertOk();

    $this->postJson('/api/v1/inventory/repair-orders', ['stock_serial_number_id' => $this->serial->id, 'fault_description' => 'X'])
        ->assertCreated()
        ->assertJsonPath('is_under_warranty', false);
});

test('a repair walks its statuses and closes the serial', function (): void {
    $id = openRepair();
    $step = fn (string $status, array $extra = []) => $this->postJson("/api/v1/inventory/repair-orders/{$id}/status", ['status' => $status] + $extra);

    $step('delivered')->assertStatus(422);
    $step('diagnosing', ['diagnosis' => 'Alimentatore'])->assertOk();
    $step('repairing')->assertOk();
    $step('ready', ['labour_amount' => '85.00', 'resolution' => 'Sostituito'])->assertOk()->assertJsonPath('labour_amount', '85.00');
    $step('cancelled')->assertStatus(422);
    $step('delivered')->assertOk()->assertJsonPath('diagnosis', 'Alimentatore');

    expect($this->serial->fresh()->status)->toBe('delivered');
});

test('spare parts leave stock and add their value to the repair', function (): void {
    $id = openRepair();
    $part = logisticsItem(['tracking_type' => 'none', 'standard_cost' => 12.5, 'current_moving_average_cost' => 12.5]);
    $bin = logisticsBin(logisticsWarehouse());
    $quant = logisticsQuant($bin, $part, '5');

    $this->postJson("/api/v1/inventory/repair-orders/{$id}/parts", ['stock_item_id' => $part->id, 'quantity' => 2, 'source_bin_id' => $bin->id])
        ->assertCreated()
        ->assertJsonPath('line_total', '25.00');

    $this->postJson("/api/v1/inventory/repair-orders/{$id}/parts", ['stock_item_id' => $part->id, 'quantity' => 9, 'source_bin_id' => $bin->id])->assertStatus(422);

    expect((string) $quant->fresh()->quantity_on_hand)->toBe('3.0000');
    $this->getJson("/api/v1/inventory/repair-orders/{$id}")->assertJsonPath('parts_amount', '25.00')->assertJsonCount(1, 'lines');
});

test('repairs stay within the tenant and need inventory write access', function (): void {
    $id = openRepair();

    logisticsUser($this->tenant, 'sales');
    $this->getJson("/api/v1/inventory/repair-orders/{$id}")->assertOk();
    $this->postJson("/api/v1/inventory/repair-orders/{$id}/status", ['status' => 'diagnosing'])->assertForbidden();

    logisticsUser(logisticsTenant('Beta'));
    $this->getJson("/api/v1/inventory/repair-orders/{$id}")->assertNotFound();
    $this->getJson('/api/v1/inventory/warranties/lookup?serial_number=SN-100')->assertNotFound();
    $this->postJson('/api/v1/inventory/repair-orders', ['stock_serial_number_id' => $this->serial->id, 'fault_description' => 'X'])->assertStatus(422);
});
