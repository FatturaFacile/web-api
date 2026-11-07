<?php

declare(strict_types=1);

use App\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Invoicing\Models\SalesDeliveryNote;
use Modules\Invoicing\Models\SalesDeliveryNoteItem;
use Modules\Invoicing\Services\InvoicingService;

require_once __DIR__.'/fixtures.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = logisticsTenant();
    logisticsUser($this->tenant);
    $this->vehicle = $this->postJson('/api/v1/inventory/weighbridge/vehicles', ['license_plate' => 'TI 1', 'card_number' => 'C-1', 'stored_tare_kg' => 7000])->assertCreated()->json('id');
    $this->contact = Contact::create(['company_name' => 'Cliente']);
    $this->note = SalesDeliveryNote::create(['tenant_id' => $this->tenant->id, 'contact_id' => $this->contact->id, 'ddt_number' => 'DDT-'.Str::random(6), 'date' => '2026-11-01', 'status' => 'draft']);
    $this->line = SalesDeliveryNoteItem::create(['tenant_id' => $this->tenant->id, 'delivery_note_id' => $this->note->id, 'description' => 'Ghiaia', 'quantity' => 1, 'unit_price' => 30, 'tax_rate' => 8.1]);
});

function closedTicket(float $first = 7000, float $second = 25000): string
{
    $id = test()->postJson('/api/v1/inventory/weighbridge/tickets', ['weighbridge_vehicle_id' => test()->vehicle, 'direction' => 'outbound', 'weight_kg' => $first])->assertCreated()->json('id');
    test()->postJson("/api/v1/inventory/weighbridge/tickets/{$id}/second-weighing", ['weight_kg' => $second])->assertOk();

    return $id;
}

test('gross, tare and net follow the two weighings in either direction', function (float $first, float $second): void {
    $id = $this->postJson('/api/v1/inventory/weighbridge/tickets', ['weighbridge_vehicle_id' => $this->vehicle, 'direction' => 'inbound', 'weight_kg' => $first])
        ->assertCreated()
        ->assertJsonPath('status', 'open')
        ->json('id');

    $this->postJson("/api/v1/inventory/weighbridge/tickets/{$id}/second-weighing", ['weight_kg' => $second])
        ->assertOk()
        ->assertJsonPath('status', 'closed')
        ->assertJsonPath('gross_kg', '25000.000')
        ->assertJsonPath('tare_kg', '7000.000')
        ->assertJsonPath('net_kg', '18000.000');
})->with([[7000, 25000], [25000, 7000]]);

test('the stored tare closes a ticket with one crossing', function (): void {
    $id = $this->postJson('/api/v1/inventory/weighbridge/tickets', ['weighbridge_vehicle_id' => $this->vehicle, 'direction' => 'outbound', 'weight_kg' => 19000])->json('id');

    $this->postJson("/api/v1/inventory/weighbridge/tickets/{$id}/second-weighing", ['use_stored_tare' => true])
        ->assertOk()
        ->assertJsonPath('used_stored_tare', true)
        ->assertJsonPath('net_kg', '12000.000');

    $this->postJson("/api/v1/inventory/weighbridge/tickets/{$id}/second-weighing", ['weight_kg' => 1])->assertStatus(422);
});

test('a vehicle without stored tare needs a second weighing', function (): void {
    $vehicle = $this->postJson('/api/v1/inventory/weighbridge/vehicles', ['license_plate' => 'TI 2'])->json('id');
    $id = $this->postJson('/api/v1/inventory/weighbridge/tickets', ['weighbridge_vehicle_id' => $vehicle, 'direction' => 'outbound', 'weight_kg' => 19000])->json('id');

    $this->postJson("/api/v1/inventory/weighbridge/tickets/{$id}/second-weighing", ['use_stored_tare' => true])->assertStatus(422);
    $this->postJson("/api/v1/inventory/weighbridge/tickets/{$id}/second-weighing", [])->assertStatus(422);
});

test('device readings can be read back and used as weights', function (): void {
    $this->postJson('/api/v1/inventory/weighbridge/readings', ['device_code' => 'P1', 'weight_kg' => 100, 'read_at' => now()->subMinute()->toIso8601String()])->assertCreated();
    $reading = $this->postJson('/api/v1/inventory/weighbridge/readings', ['device_code' => 'P1', 'weight_kg' => 18240.5])->assertCreated();

    $this->getJson('/api/v1/inventory/weighbridge/readings/latest?device_code=P1')->assertOk()->assertJsonPath('weight_kg', '18240.500');
    $this->getJson('/api/v1/inventory/weighbridge/readings/latest?device_code=NONE')->assertNotFound();

    $this->postJson('/api/v1/inventory/weighbridge/tickets', ['weighbridge_vehicle_id' => $this->vehicle, 'direction' => 'inbound', 'reading_id' => $reading->json('id')])
        ->assertCreated()
        ->assertJsonPath('first_weight_kg', '18240.500');
});

test('a ticket bills in kg, tonnes or m3 on the delivery line', function (string $uom, ?float $density, string $quantity): void {
    $id = closedTicket();

    $this->postJson("/api/v1/inventory/weighbridge/tickets/{$id}/bill", ['delivery_note_item_id' => $this->line->id, 'billing_uom' => $uom, 'density_kg_m3' => $density])
        ->assertOk()
        ->assertJsonPath('status', 'billed')
        ->assertJsonPath('billed_quantity', $quantity);

    expect((string) $this->line->fresh()->quantity)->toBe($quantity)
        ->and($this->line->fresh()->uom)->toBe($uom)
        ->and($this->line->fresh()->weighbridge_ticket_id)->toBe($id);
})->with([['kg', null, '18000.0000'], ['t', null, '18.0000'], ['m3', 1600, '11.2500']]);

test('the invoice of the delivery note carries the billed unit', function (): void {
    $this->postJson('/api/v1/inventory/weighbridge/tickets/'.closedTicket().'/bill', ['delivery_note_item_id' => $this->line->id, 'billing_uom' => 't'])->assertOk();

    $invoice = app(InvoicingService::class)->createInvoiceFromDeliveryNote($this->note->fresh());

    expect($invoice->items->first()->uom)->toBe('t')
        ->and((string) $invoice->total_net)->toBe('540.00');
});

test('m3 needs a density, a ticket bills once and only when closed, and an invoiced note is left alone', function (): void {
    $id = closedTicket();
    $open = $this->postJson('/api/v1/inventory/weighbridge/tickets', ['weighbridge_vehicle_id' => $this->vehicle, 'direction' => 'outbound', 'weight_kg' => 100])->json('id');
    $payload = ['delivery_note_item_id' => $this->line->id, 'billing_uom' => 'kg'];

    $this->postJson("/api/v1/inventory/weighbridge/tickets/{$id}/bill", ['billing_uom' => 'm3'] + $payload)->assertStatus(422);
    $this->postJson("/api/v1/inventory/weighbridge/tickets/{$open}/bill", $payload)->assertStatus(422);
    $this->postJson("/api/v1/inventory/weighbridge/tickets/{$id}/bill", $payload)->assertOk();
    $this->postJson("/api/v1/inventory/weighbridge/tickets/{$id}/bill", $payload)->assertStatus(422);

    $this->note->update(['status' => 'invoiced']);
    $this->postJson('/api/v1/inventory/weighbridge/tickets/'.closedTicket().'/bill', $payload)->assertStatus(422);
});

test('weighbridge data stays within the tenant, and a read-only role cannot weigh', function (): void {
    $id = closedTicket();

    logisticsUser($this->tenant, 'sales');
    $this->getJson('/api/v1/inventory/weighbridge/tickets')->assertOk()->assertJsonCount(1, 'data');
    $this->postJson('/api/v1/inventory/weighbridge/readings', ['device_code' => 'P', 'weight_kg' => 1])->assertForbidden();

    logisticsUser(logisticsTenant('Beta'));
    $this->getJson('/api/v1/inventory/weighbridge/tickets')->assertOk()->assertJsonCount(0, 'data');
    $this->postJson("/api/v1/inventory/weighbridge/tickets/{$id}/bill", ['delivery_note_item_id' => $this->line->id, 'billing_uom' => 'kg'])->assertStatus(422);
    $this->postJson('/api/v1/inventory/weighbridge/tickets', ['weighbridge_vehicle_id' => $this->vehicle, 'direction' => 'inbound', 'weight_kg' => 1])->assertStatus(422);
});
