<?php

declare(strict_types=1);

use App\Contracts\Security\HostResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Modules\Inventory\Contracts\ShippingCarrierDriver;
use Modules\Inventory\DTOs\CarrierBooking;
use Modules\Inventory\DTOs\CarrierTrackingEvent;
use Modules\Inventory\Exceptions\CarrierException;
use Modules\Inventory\Models\Shipment;
use Modules\Inventory\Models\ShippingCarrier;
use Modules\Inventory\Services\Shipping\LocalCarrierDriver;
use Tests\Support\StaticHostResolver;

require_once __DIR__.'/fixtures.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = logisticsTenant();
    logisticsUser($this->tenant);
    $this->recipient = ['name' => 'Rossi <SA>', 'address' => 'Via Roma 1', 'city' => 'Lugano', 'postal_code' => '6900', 'country' => 'CH'];
});

function carrierFor(string $code = 'OWN'): array
{
    return test()->postJson('/api/v1/inventory/carriers', [
        'code' => $code, 'name' => 'Furgoni propri', 'driver' => 'local', 'credentials' => ['secret' => 'x'], 'tracking_url_template' => 'https://track.example/{tracking_number}',
    ])->assertCreated()->json();
}

test('a carrier keeps its credentials to itself and encrypted', function (): void {
    $carrier = carrierFor();

    expect($carrier)->not->toHaveKey('credentials')
        ->and(ShippingCarrier::findOrFail($carrier['id'])->credentials)->toBe(['secret' => 'x'])
        ->and(ShippingCarrier::query()->toBase()->where('id', $carrier['id'])->value('credentials'))->not->toContain('secret');
});

test('an unknown driver is refused', function (): void {
    $this->postJson('/api/v1/inventory/carriers', ['code' => 'X', 'name' => 'X', 'driver' => 'unknown'])->assertStatus(422);
});

test('the local driver books a shipment with tracking number and label', function (): void {
    $carrier = carrierFor();

    $shipment = $this->postJson('/api/v1/inventory/shipments', ['shipping_carrier_id' => $carrier['id'], 'recipient' => $this->recipient, 'packages_count' => 2, 'gross_weight_kg' => 12.5])
        ->assertCreated()
        ->assertJsonPath('status', 'booked')
        ->assertJsonPath('label_format', 'html')
        ->assertJsonPath('tracking_events.0.status', 'booked');

    expect($shipment->json('tracking_number'))->toStartWith('OWN-')
        ->and($shipment->json('tracking_url'))->toBe('https://track.example/'.$shipment->json('tracking_number'));

    $label = $this->get("/api/v1/inventory/shipments/{$shipment->json('id')}/label")->assertOk();

    expect($label->getContent())->toContain($shipment->json('tracking_number'))->toContain('<svg')->toContain('Rossi &lt;SA&gt;');
});

test('packages and weight come from the packing list', function (): void {
    $warehouse = logisticsWarehouse();
    $item = logisticsItem(['tracking_type' => 'none', 'units_per_carton' => 2, 'net_weight_kg' => '1.000', 'carton_tare_kg' => '0.500']);
    logisticsQuant(logisticsBin($warehouse), $item, '4');
    $list = $this->postJson('/api/v1/inventory/pick-lists', ['warehouse_id' => $warehouse->id, 'items' => [['stock_item_id' => $item->id, 'quantity' => 4]]]);
    $this->postJson("/api/v1/inventory/pick-lists/{$list->json('id')}/lines/{$list->json('lines.0.id')}/confirm", ['quantity' => 4])->assertOk();
    $packing = $this->postJson("/api/v1/inventory/pick-lists/{$list->json('id')}/packing-list")->assertCreated();

    $shipment = $this->postJson('/api/v1/inventory/shipments', ['shipping_carrier_id' => carrierFor()['id'], 'recipient' => $this->recipient, 'stock_packing_list_id' => $packing->json('id')])
        ->assertCreated()
        ->assertJsonPath('packages_count', 2)
        ->assertJsonPath('gross_weight_kg', '5.000');

    $this->assertDatabaseHas('stock_packing_lists', ['id' => $packing->json('id'), 'tracking_number' => $shipment->json('tracking_number')]);
});

test('tracking events move the shipment and a delivered shipment stays delivered', function (): void {
    $shipment = $this->postJson('/api/v1/inventory/shipments', ['shipping_carrier_id' => carrierFor()['id'], 'recipient' => $this->recipient])->assertCreated();
    $id = $shipment->json('id');

    $this->postJson("/api/v1/inventory/shipments/{$id}/tracking", ['status' => 'in_transit', 'location' => 'Chiasso'])->assertCreated()->assertJsonPath('status', 'in_transit');
    $this->postJson("/api/v1/inventory/shipments/{$id}/tracking", ['status' => 'delivered'])->assertCreated()->assertJsonPath('status', 'delivered');
    $this->postJson("/api/v1/inventory/shipments/{$id}/tracking", ['status' => 'in_transit', 'occurred_at' => now()->subHour()->toIso8601String()])->assertCreated()->assertJsonPath('status', 'delivered');
    $this->postJson("/api/v1/inventory/shipments/{$id}/tracking", ['status' => 'lost'])->assertStatus(422);

    expect(Shipment::findOrFail($id)->delivered_at)->not->toBeNull();
});

test('a driver reports news and a carrier failure answers 502 and books nothing', function (): void {
    $carrier = ShippingCarrier::findOrFail(carrierFor()['id']);

    $driver = new class implements ShippingCarrierDriver
    {
        public bool $fail = false;

        public function book(ShippingCarrier $carrier, Shipment $shipment): CarrierBooking
        {
            if ($this->fail) {
                throw new CarrierException('Refused by the carrier.');
            }

            return new CarrierBooking('TRK1', 'html', '<b>label</b>', 'REF1');
        }

        public function track(ShippingCarrier $carrier, Shipment $shipment): array
        {
            return [new CarrierTrackingEvent('delivered', '2026-11-05T10:00:00+00:00', 'Signed', 'Lugano')];
        }
    };

    $this->app->instance(LocalCarrierDriver::class, $driver);

    $shipment = $this->postJson('/api/v1/inventory/shipments', ['shipping_carrier_id' => $carrier->id, 'recipient' => $this->recipient])->assertCreated();

    $this->postJson("/api/v1/inventory/shipments/{$shipment->json('id')}/refresh")->assertOk()->assertJsonPath('status', 'delivered');
    $this->postJson("/api/v1/inventory/shipments/{$shipment->json('id')}/refresh")->assertOk()->assertJsonCount(2, 'tracking_events');

    $driver->fail = true;

    $this->postJson('/api/v1/inventory/shipments', ['shipping_carrier_id' => $carrier->id, 'recipient' => $this->recipient])->assertStatus(502);
    expect(Shipment::query()->count())->toBe(1);
});

function dpdCarrier(): ShippingCarrier
{
    return ShippingCarrier::findOrFail(test()->postJson('/api/v1/inventory/carriers', [
        'code' => 'DPD', 'name' => 'DPD Svizzera', 'driver' => 'dpd_ch', 'contract_number' => '12345678',
        'credentials' => [
            'username' => 'user', 'password' => 'pass', 'base_url' => 'https://dpd.test',
            'sender' => ['name' => 'Alfa SA', 'street' => 'Via Lago 2', 'zip_code' => '6900', 'city' => 'Lugano', 'country_code' => 'CH'],
        ],
    ])->assertCreated()->json('id'));
}

test('the dpd switzerland driver logs in, books with the documented body and stores the pdf label', function (): void {
    Http::fake([
        'dpd.test/api/v1/login' => Http::response(['token' => 'jwt', 'refreshToken' => 'r']),
        'dpd.test/api/v1/shipments' => Http::response([
            'tracingId' => 'TRACE-1',
            'label' => base64_encode('%PDF-label'),
            'success' => [['tracingId' => 'T1', 'parcelNumbers' => [['serviceCode' => 'B2B', 'number' => '05305000123456'], ['serviceCode' => 'B2B', 'number' => '05305000123457']]]],
            'failed' => [],
        ], 201),
    ]);

    $shipment = $this->postJson('/api/v1/inventory/shipments', ['shipping_carrier_id' => dpdCarrier()->id, 'recipient' => $this->recipient, 'packages_count' => 2, 'gross_weight_kg' => 3.5])
        ->assertCreated()
        ->assertJsonPath('tracking_number', '05305000123456')
        ->assertJsonPath('label_format', 'pdf');

    Http::assertSent(static function (HttpRequest $request): bool {
        if (! str_ends_with($request->url(), '/api/v1/shipments')) {
            return $request['username'] === 'user';
        }

        $body = $request->data()[0];

        return $request->hasHeader('Authorization', 'Bearer jwt')
            && $body['invoicingNumber'] === '12345678'
            && $body['numberOfParcels'] === 2
            && $body['receiver']['zipCode'] === '6900'
            && $body['sender']['street'] === 'Via Lago 2'
            && $body['parcelInfo'] === [['serviceCode' => 'B2B', 'weight' => 1750], ['serviceCode' => 'B2B', 'weight' => 1750]];
    });

    $label = $this->get("/api/v1/inventory/shipments/{$shipment->json('id')}/label")->assertOk();

    expect($label->getContent())->toBe('%PDF-label')
        ->and($label->headers->get('Content-Type'))->toBe('application/pdf');
});

test('the dpd switzerland driver maps tracking statuses and skips steps not reached', function (): void {
    Http::fake([
        'dpd.test/api/v1/login' => Http::response(['token' => 'jwt']),
        'dpd.test/api/v1/shipments' => Http::response(['label' => 'eA==', 'success' => [['parcelNumbers' => [['number' => '0530']]]], 'failed' => []], 201),
        'dpd.test/api/v1/tracking/*' => Http::response(['statusInfo' => [
            ['name' => 'ACCEPTED', 'displayName' => 'Consegnato a DPD', 'location' => 'Zurigo', 'date' => '2026-11-05 08:00:00', 'hasBeenReached' => true, 'isCurrent' => false],
            ['name' => 'AT_DELIVERY_DEPOT', 'displayName' => 'In consegna', 'location' => '', 'date' => '2026-11-05 10:00:00', 'hasBeenReached' => true, 'isCurrent' => true],
            ['name' => 'DELIVERED', 'displayName' => 'Consegnato', 'location' => '', 'date' => '', 'hasBeenReached' => false, 'isCurrent' => false],
        ]]),
    ]);

    $shipment = $this->postJson('/api/v1/inventory/shipments', ['shipping_carrier_id' => dpdCarrier()->id, 'recipient' => $this->recipient])->assertCreated();

    $this->postJson("/api/v1/inventory/shipments/{$shipment->json('id')}/refresh")
        ->assertOk()
        ->assertJsonPath('status', 'out_for_delivery')
        ->assertJsonCount(3, 'tracking_events')
        ->assertJsonPath('tracking_events.0.location', 'Zurigo');
});

test('dpd refusals become a 502 and international shipments are refused before calling it', function (): void {
    Http::fake([
        'dpd.test/api/v1/login' => Http::response(['token' => 'jwt']),
        'dpd.test/api/v1/shipments' => Http::response(['success' => [], 'failed' => [['reason' => 'Invalid zip']]], 400),
    ]);
    $carrier = dpdCarrier();

    $this->postJson('/api/v1/inventory/shipments', ['shipping_carrier_id' => $carrier->id, 'recipient' => $this->recipient])
        ->assertStatus(502)
        ->assertJsonPath('message', 'DPD refused the shipment: Invalid zip');

    $this->postJson('/api/v1/inventory/shipments', ['shipping_carrier_id' => $carrier->id, 'recipient' => ['country' => 'DE'] + $this->recipient])->assertStatus(422);

    expect(Shipment::query()->count())->toBe(0);
});

test('dpd carrier credentials accept only known keys and a public https base url', function (): void {
    app()->instance(HostResolver::class, new StaticHostResolver(['dpd.internal-staging.com' => ['10.20.0.5']]));
    $carrier = fn (array $credentials): array => ['code' => 'DPD', 'name' => 'DPD', 'driver' => 'dpd_ch', 'contract_number' => '1', 'credentials' => $credentials];
    $valid = ['username' => 'u', 'password' => 'p', 'sender' => ['name' => 'Alfa SA', 'street' => 'Via Lago 2', 'zip_code' => '6900', 'city' => 'Lugano', 'country_code' => 'CH']];

    foreach (['http://dpd.test', 'https://127.0.0.1', 'https://2130706433', 'https://dpd.internal-staging.com', 'https://[::1]', 'file:///etc/passwd'] as $url) {
        $this->postJson('/api/v1/inventory/carriers', $carrier($valid + ['base_url' => $url]))->assertUnprocessable()->assertJsonValidationErrors('credentials.base_url');
    }

    $this->postJson('/api/v1/inventory/carriers', $carrier($valid + ['proxy' => 'http://10.0.0.1']))->assertUnprocessable()->assertJsonValidationErrors('credentials');
    $this->postJson('/api/v1/inventory/carriers', $carrier(['sender' => ['name' => 'A', 'header' => 'x']] + $valid))->assertUnprocessable()->assertJsonValidationErrors('credentials.sender');
    $this->postJson('/api/v1/inventory/carriers', $carrier($valid + ['base_url' => 'https://dpd.test']))->assertCreated();
});

test('a stored dpd base url on a private network is never called and remote reasons are cut short', function (): void {
    Http::fake([
        'dpd.test/api/v1/login' => Http::response(['token' => 'jwt']),
        'dpd.test/api/v1/shipments' => Http::response(['success' => [], 'failed' => [['reason' => "<html>\u{0007}".str_repeat('internal stack trace ', 40).'</html>']]], 400),
    ]);
    $carrier = dpdCarrier();

    $message = $this->postJson('/api/v1/inventory/shipments', ['shipping_carrier_id' => $carrier->id, 'recipient' => $this->recipient])->assertStatus(502)->json('message');

    expect($message)->toStartWith('DPD refused the shipment: html internal stack trace')
        ->and($message)->not->toContain('<')
        ->and(mb_strlen($message))->toBeLessThanOrEqual(160);

    $carrier->forceFill(['credentials' => array_merge($carrier->credentials, ['base_url' => 'https://metadata.dpd.test'])])->save();
    app()->instance(HostResolver::class, new StaticHostResolver(['metadata.dpd.test' => ['169.254.169.254']]));

    $this->postJson('/api/v1/inventory/shipments', ['shipping_carrier_id' => $carrier->id, 'recipient' => $this->recipient])->assertStatus(502)
        ->assertJsonPath('message', 'The DPD base URL of carrier [DPD] is not a public https address.');

    Http::assertSentCount(2);
});

test('another tenant sees neither carriers nor shipments, and a read-only role cannot book', function (): void {
    $carrier = carrierFor();
    $shipment = $this->postJson('/api/v1/inventory/shipments', ['shipping_carrier_id' => $carrier['id'], 'recipient' => $this->recipient])->assertCreated();

    logisticsUser($this->tenant, 'sales');
    $this->postJson('/api/v1/inventory/shipments', ['shipping_carrier_id' => $carrier['id'], 'recipient' => $this->recipient])->assertForbidden();
    $this->getJson("/api/v1/inventory/shipments/{$shipment->json('id')}")->assertOk();

    logisticsUser(logisticsTenant('Beta'));
    $this->getJson("/api/v1/inventory/shipments/{$shipment->json('id')}")->assertNotFound();
    $this->getJson('/api/v1/inventory/carriers')->assertOk()->assertJsonCount(0, 'data');
    $this->postJson('/api/v1/inventory/shipments', ['shipping_carrier_id' => $carrier['id'], 'recipient' => $this->recipient])->assertStatus(422);
});
