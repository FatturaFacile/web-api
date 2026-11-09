<?php

declare(strict_types=1);

use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\Subscription;
use App\Models\TireHotelRecord;
use App\Models\Vehicle;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Enterprise\EventBus\Models\TransactionalOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Invoicing\Models\SalesDeliveryNote;
use Modules\SubscriptionBilling\Contracts\RecurringBillingServiceInterface;
use Modules\Workshop\Models\VehicleStockUnit;
use Modules\Workshop\Models\WorkshopFolder;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2026-11-07 10:00:00');
    $this->tenant = verticalTenant();
    $this->customer = verticalContact($this->tenant);
    $this->vehicle = Vehicle::factory()->create(['tenant_id' => $this->tenant->id, 'client_id' => $this->customer->id, 'license_plate' => 'TI 123456']);
    $this->user = verticalMember($this->tenant, 'admin');
    verticalApi($this->user, $this->tenant);

    $this->openFolder = function (): string {
        $id = $this->postJson('/api/v1/workshop/folders', [
            'vehicle_id' => $this->vehicle->id, 'kind' => 'mechanics', 'currency' => 'CHF', 'mileage_in' => 999999, 'complaint' => 'Rumore freni',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/workshop/folders/{$id}/lines", ['line_type' => 'labour', 'description' => 'Sostituzione pastiglie', 'quantity' => '1.5', 'unit_price' => '120', 'tax_rate' => '8.1'])->assertCreated();
        $this->postJson("/api/v1/workshop/folders/{$id}/lines", ['line_type' => 'part', 'description' => 'Pastiglie', 'quantity' => '1', 'unit_price' => '80.50', 'discount_percentage' => '10', 'tax_rate' => '8.1'])
            ->assertCreated()->assertJsonPath('data.line_total', '72.45');

        return $id;
    };
});

test('a folder runs from reception to quote, delivery note and a single invoice', function (): void {
    verticalLedger($this->tenant);
    $id = ($this->openFolder)();

    expect(Vehicle::query()->findOrFail($this->vehicle->id)->mileage_km)->toBe(999999);

    $quote = $this->postJson("/api/v1/workshop/folders/{$id}/quote", ['valid_until' => '2026-12-01'])->assertCreated();
    expect((float) Quote::query()->findOrFail($quote->json('data.document_id'))->total_net)->toBe(252.45);

    $this->postJson("/api/v1/workshop/folders/{$id}/delivery-note")->assertUnprocessable();
    $this->postJson("/api/v1/workshop/folders/{$id}/invoice")->assertUnprocessable();
    $this->postJson("/api/v1/workshop/folders/{$id}/status", ['status' => 'delivered'])->assertUnprocessable();
    $this->postJson("/api/v1/workshop/folders/{$id}/status", ['status' => 'in_progress'])->assertOk();
    $this->postJson("/api/v1/workshop/folders/{$id}/status", ['status' => 'completed', 'mileage_out' => 1000005])->assertOk()->assertJsonPath('data.status', 'completed');

    $note = $this->postJson("/api/v1/workshop/folders/{$id}/delivery-note")->assertCreated();
    expect(DeliveryNote::query()->findOrFail($note->json('data.document_id'))->items()->count())->toBe(2);
    $this->postJson("/api/v1/workshop/folders/{$id}/delivery-note")->assertUnprocessable();

    $response = $this->postJson("/api/v1/workshop/folders/{$id}/invoice", ['date' => '2026-11-07']);
    expect($response->status())->toBe(201, (string) $response->getContent());

    $invoice = Invoice::query()->findOrFail($response->json('data.document_id'));
    expect((float) $invoice->total_net)->toBe(252.45)
        ->and((string) $invoice->contact_id)->toBe((string) $this->customer->id)
        ->and((string) $invoice->quote_id)->toBe((string) $quote->json('data.document_id'))
        ->and((string) $invoice->delivery_note_id)->toBe((string) $note->json('data.document_id'));

    $this->postJson("/api/v1/workshop/folders/{$id}/invoice")->assertUnprocessable();
    $this->postJson("/api/v1/workshop/folders/{$id}/lines", ['line_type' => 'other', 'description' => 'x', 'quantity' => '1', 'unit_price' => '1', 'tax_rate' => '8.1'])->assertUnprocessable();
    expect(Invoice::query()->count())->toBe(1);
});

test('a stock vehicle is sold once, joins the vehicles of the buyer and is invoiced', function (): void {
    verticalLedger($this->tenant);
    $buyer = verticalContact($this->tenant, 'Acquirente SA');
    verticalApi($this->user, $this->tenant);

    $unitId = $this->postJson('/api/v1/workshop/vehicle-stock', [
        'stock_number' => 'VS-1', 'condition' => 'used', 'make' => 'Skoda', 'model' => 'Octavia', 'vin' => 'TMB123', 'list_price' => '15000', 'currency' => 'CHF',
    ])->assertCreated()->json('data.id');

    $this->postJson("/api/v1/workshop/vehicle-stock/{$unitId}/sell", ['buyer_contact_id' => $buyer->id, 'sale_date' => '2026-11-07', 'sale_price' => '14500', 'tax_rate' => '8.1'])
        ->assertUnprocessable()->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'license plate'));

    $sale = $this->postJson("/api/v1/workshop/vehicle-stock/{$unitId}/sell", ['buyer_contact_id' => $buyer->id, 'sale_date' => '2026-11-07', 'sale_price' => '14500', 'tax_rate' => '8.1', 'license_plate' => 'TI 999'])
        ->assertCreated();

    expect((float) Invoice::query()->findOrFail($sale->json('data.invoice_id'))->total_net)->toBe(14500.0)
        ->and(Vehicle::query()->where('license_plate', 'TI 999')->value('client_id'))->toBe((string) $buyer->id)
        ->and(VehicleStockUnit::query()->findOrFail($unitId)->status)->toBe('sold');

    $this->postJson("/api/v1/workshop/vehicle-stock/{$unitId}/sell", ['buyer_contact_id' => $buyer->id, 'sale_date' => '2026-11-07', 'sale_price' => '1', 'tax_rate' => '8.1'])->assertUnprocessable();
    $this->putJson("/api/v1/workshop/vehicle-stock/{$unitId}", ['list_price' => '1'])->assertUnprocessable();
});

test('yearly tyre storage is billed automatically through the subscriptions', function (): void {
    verticalLedger($this->tenant);
    verticalLedger($this->tenant, 2027);
    verticalApi($this->user, $this->tenant);
    $record = TireHotelRecord::create([
        'tenant_id' => $this->tenant->id, 'vehicle_id' => $this->vehicle->id, 'season' => 'winter', 'brand' => 'Pirelli', 'size' => '205/55 R16',
        'front_left_tread_mm' => 6, 'front_right_tread_mm' => 6, 'rear_left_tread_mm' => 6, 'rear_right_tread_mm' => 6, 'status' => 'stored',
    ]);

    $service = $this->postJson('/api/v1/workshop/recurring-services', [
        'vehicle_id' => $this->vehicle->id, 'tire_hotel_record_id' => $record->id, 'service_type' => 'tyre_storage', 'name' => 'Deposito gomme',
        'interval' => 'yearly', 'start_date' => '2026-11-07', 'currency' => 'CHF', 'unit_price' => '90', 'tax_rate' => '8.1',
    ])->assertCreated();

    $subscription = Subscription::query()->findOrFail($service->json('data.subscription_id'));
    expect((string) $subscription->contact_id)->toBe((string) $this->customer->id);

    $this->travelTo('2027-11-08 09:00:00');
    app(RecurringBillingServiceInterface::class)->billSubscription((string) $subscription->id, CarbonImmutable::parse('2027-11-08'));

    expect(Invoice::query()->where('subscription_id', $subscription->id)->count())->toBe(2);

    $this->postJson("/api/v1/workshop/recurring-services/{$service->json('data.id')}/stop")->assertOk()->assertJsonPath('data.subscription.status', 'cancelled');
});

test('a stored tyre set of another vehicle is refused', function (): void {
    $other = Vehicle::factory()->create(['tenant_id' => $this->tenant->id, 'client_id' => $this->customer->id]);
    $record = TireHotelRecord::create([
        'tenant_id' => $this->tenant->id, 'vehicle_id' => $other->id, 'season' => 'winter', 'brand' => 'Pirelli', 'size' => '205/55 R16',
        'front_left_tread_mm' => 6, 'front_right_tread_mm' => 6, 'rear_left_tread_mm' => 6, 'rear_right_tread_mm' => 6, 'status' => 'stored',
    ]);
    verticalApi($this->user, $this->tenant);

    $this->postJson('/api/v1/workshop/recurring-services', [
        'vehicle_id' => $this->vehicle->id, 'tire_hotel_record_id' => $record->id, 'service_type' => 'tyre_storage', 'name' => 'Deposito',
        'interval' => 'yearly', 'start_date' => '2026-11-07', 'currency' => 'CHF', 'unit_price' => '90', 'tax_rate' => '8.1',
    ])->assertUnprocessable();
});

test('another company sees neither the folders nor the vehicles of the tenant', function (): void {
    $id = ($this->openFolder)();
    $other = verticalTenant();
    $stranger = verticalMember($other, 'admin');
    $foreignVehicle = Vehicle::factory()->create(['tenant_id' => $other->id]);
    verticalApi($stranger, $other);

    $this->getJson("/api/v1/workshop/folders/{$id}")->assertNotFound();
    $this->getJson('/api/v1/workshop/folders')->assertOk()->assertJsonCount(0, 'data');
    $this->postJson("/api/v1/workshop/folders/{$id}/lines", ['line_type' => 'other', 'description' => 'x', 'quantity' => '1', 'unit_price' => '1', 'tax_rate' => '8.1'])->assertNotFound();

    verticalApi($this->user, $this->tenant);
    $this->postJson('/api/v1/workshop/folders', ['vehicle_id' => $foreignVehicle->id, 'kind' => 'mechanics', 'currency' => 'CHF'])->assertUnprocessable()->assertJsonValidationErrors('vehicle_id');
});

test('ordinary members stay out, technicians work folders but do not invoice', function (): void {
    verticalLedger($this->tenant);
    $id = ($this->openFolder)();
    app(TenantContext::class)->setTenant($this->tenant);
    WorkshopFolder::query()->whereKey($id)->update(['status' => 'completed']);

    verticalApi(verticalMember($this->tenant, 'member'), $this->tenant);
    $this->getJson('/api/v1/workshop/folders')->assertForbidden();

    verticalApi(verticalMember($this->tenant, 'accountant_readonly'), $this->tenant);
    $this->getJson('/api/v1/workshop/folders')->assertOk();
    $this->postJson("/api/v1/workshop/folders/{$id}/status", ['status' => 'delivered'])->assertForbidden();

    verticalApi(verticalMember($this->tenant, 'technician'), $this->tenant);
    $this->postJson("/api/v1/workshop/folders/{$id}/status", ['status' => 'delivered'])->assertOk();
    $this->postJson("/api/v1/workshop/folders/{$id}/invoice")->assertForbidden();

    verticalApi(verticalMember($this->tenant, 'sales'), $this->tenant);
    $this->postJson("/api/v1/workshop/folders/{$id}/invoice", ['date' => '2026-11-07'])->assertCreated();
});

test('customer vehicles can be registered, changed and archived, and then opened as a folder', function (): void {
    $other = verticalTenant();
    $foreignContact = verticalContact($other, 'Altro SA');
    app(TenantContext::class)->setTenant($this->tenant);

    $this->postJson('/api/v1/automotive/vehicles', ['client_id' => $foreignContact->id, 'license_plate' => 'ZH 1', 'make' => 'Fiat', 'model' => 'Panda'])
        ->assertUnprocessable()->assertJsonValidationErrors('client_id');
    $this->postJson('/api/v1/automotive/vehicles', ['client_id' => $this->customer->id, 'license_plate' => 'ti 123456', 'make' => 'Fiat', 'model' => 'Panda'])
        ->assertUnprocessable()->assertJsonValidationErrors('license_plate');

    $id = $this->postJson('/api/v1/automotive/vehicles', [
        'client_id' => $this->customer->id, 'license_plate' => 'ge 98765', 'vin_chassis' => 'zfa31200000123456', 'make' => 'Fiat', 'model' => '500', 'year' => 2021, 'mileage_km' => 42000,
    ])->assertCreated()->assertJsonPath('data.license_plate', 'GE 98765')->json('data.id');

    expect((string) Vehicle::query()->findOrFail($id)->tenant_id)->toBe((string) $this->tenant->id);

    $this->getJson("/api/v1/automotive/vehicles/{$id}")->assertOk()->assertJsonPath('data.client.id', $this->customer->id);
    $this->patchJson("/api/v1/automotive/vehicles/{$id}", ['mileage_km' => 43500])->assertOk()->assertJsonPath('data.mileage_km', 43500);
    $this->postJson('/api/v1/workshop/folders', ['vehicle_id' => $id, 'kind' => 'mechanics', 'currency' => 'CHF'])->assertCreated();

    $this->deleteJson("/api/v1/automotive/vehicles/{$id}")->assertNoContent();
    expect(Vehicle::withTrashed()->findOrFail($id)->trashed())->toBeTrue();
    $this->getJson("/api/v1/automotive/vehicles/{$id}")->assertNotFound();
});

test('a member of another company cannot read or change a vehicle', function (): void {
    $other = verticalTenant();
    verticalApi(verticalMember($other, 'admin'), $other);

    $this->getJson("/api/v1/automotive/vehicles/{$this->vehicle->id}")->assertNotFound();
    $this->putJson("/api/v1/automotive/vehicles/{$this->vehicle->id}", ['mileage_km' => 1])->assertNotFound();
    $this->deleteJson("/api/v1/automotive/vehicles/{$this->vehicle->id}")->assertNotFound();
});

test('the delivery note of a folder is written by the sales delivery note writer: gapless number, issued and announced', function (): void {
    $notes = [];

    foreach ([1, 2] as $ignored) {
        $id = ($this->openFolder)();
        $this->postJson("/api/v1/workshop/folders/{$id}/status", ['status' => 'in_progress'])->assertOk();
        $this->postJson("/api/v1/workshop/folders/{$id}/status", ['status' => 'completed', 'mileage_out' => 1000005])->assertOk();
        $notes[] = SalesDeliveryNote::query()->findOrFail($this->postJson("/api/v1/workshop/folders/{$id}/delivery-note")->assertCreated()->json('data.document_id'));
    }

    [$first, $second] = $notes;
    $sequence = static fn (SalesDeliveryNote $note): int => (int) preg_replace('/\D+/', '', (string) str((string) $note->ddt_number)->afterLast('-'));

    expect((string) $first->tenant_id)->toBe((string) $this->tenant->id)
        ->and($first->status)->toBe('issued')
        ->and($first->shipping_status)->toBe('dispatched')
        ->and($first->items()->count())->toBe(2)
        ->and($sequence($second))->toBe($sequence($first) + 1)
        ->and(TransactionalOutbox::query()->where('event_type', 'sales.delivery_note.issued')->where('aggregate_id', $first->id)->exists())->toBeTrue()
        ->and(TransactionalOutbox::query()->where('event_type', 'sales.goods.dispatched')->where('aggregate_id', $second->id)->exists())->toBeTrue();
});
