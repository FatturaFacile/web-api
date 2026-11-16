<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Modules\Fleet\Models\FleetVehicle;
use Modules\Transportation\Models\TmsTransportOrder;
use Modules\Transportation\Models\TmsTrip;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2027-06-15 09:00:00');
    $this->tenant = tmsTenant('Spedizioni');
    $this->owner = tmsMember($this->tenant, 'owner');
    tmsAct($this->owner, $this->tenant);
});

afterEach(fn () => app(TenantContext::class)->clear());

function tmsTenant(string $name): Tenant
{
    return Tenant::create(['name' => $name.' '.Str::random(4), 'legal_name' => $name.' Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
}

function tmsMember(Tenant $tenant, string $role): User
{
    $user = User::create(['name' => ucfirst($role).' '.Str::random(4), 'email' => Str::random(10).'@tms-test.example', 'password' => bcrypt(Str::random(32)), 'locale' => 'en']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

function tmsAct(User $user, Tenant $tenant): void
{
    app(TenantContext::class)->setTenant($tenant);
    Sanctum::actingAs($user);
    test()->withHeaders(['X-Tenant-Id' => (string) $tenant->id]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function tmsCarrier(string $code, array $overrides = []): array
{
    return test()->postJson('/api/v1/transportation/carriers', ['code' => $code, 'name' => ucfirst(strtolower($code)).' Logistics', ...$overrides])->assertCreated()->json('data');
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function tmsTable(string $carrierId, array $overrides = []): array
{
    return test()->postJson('/api/v1/transportation/tariff-tables', ['carrier_id' => $carrierId, 'name' => 'Tariff 2027', 'valid_from' => '2027-04-01', ...$overrides])->assertCreated()->json('data');
}

/**
 * @param  array<string, mixed>  $overrides
 */
function tmsBand(string $tableId, array $overrides = []): TestResponse
{
    return test()->postJson('/api/v1/transportation/tariff-bands', ['tariff_table_id' => $tableId, ...$overrides]);
}

/**
 * Three carriers priced as follows for 80 kg over 420 km from zone N to zone S on 2027-06-15: Alpha 77.00 (weight bands, fuel
 * 10 percent), Beta 136.00 (distance), Gamma 64.58 (weight and distance, fuel 2.5 percent, rounded half up).
 *
 * @return array{alpha: array<string, mixed>, beta: array<string, mixed>, gamma: array<string, mixed>}
 */
function tmsTariffs(): array
{
    $alpha = tmsCarrier('alpha');
    $table = tmsTable($alpha['id'], ['rate_basis' => 'weight', 'fuel_surcharge_percent' => '10', 'minimum_charge' => '50']);
    tmsBand($table['id'], ['origin_zone' => 'N', 'destination_zone' => 'S', 'weight_from_kg' => '0', 'weight_to_kg' => '100', 'fixed_amount' => '30', 'price_per_kg' => '0.5'])->assertCreated();
    tmsBand($table['id'], ['origin_zone' => 'N', 'destination_zone' => 'S', 'weight_from_kg' => '100', 'fixed_amount' => '20', 'price_per_kg' => '0.4'])->assertCreated();

    $beta = tmsCarrier('beta');
    $betaTable = tmsTable($beta['id'], ['valid_from' => '2027-01-01', 'rate_basis' => 'distance']);
    tmsBand($betaTable['id'], ['distance_from_km' => '0', 'distance_to_km' => '500', 'fixed_amount' => '10', 'price_per_km' => '0.3'])->assertCreated();

    $gamma = tmsCarrier('gamma');
    $gammaTable = tmsTable($gamma['id'], ['valid_from' => '2027-01-01', 'rate_basis' => 'weight_distance', 'fuel_surcharge_percent' => '2.5']);
    tmsBand($gammaTable['id'], ['fixed_amount' => '5', 'price_per_kg' => '0.2', 'price_per_km' => '0.1'])->assertCreated();

    return ['alpha' => $alpha, 'beta' => $beta, 'gamma' => $gamma];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function tmsOrder(array $overrides = []): TestResponse
{
    return test()->postJson('/api/v1/transportation/transport-orders', [
        'origin_name' => 'Magazzino Nord', 'origin_location' => 'Via Roma 1, Milano', 'origin_zone' => 'N',
        'destination_name' => 'Cliente Sud', 'destination_location' => 'Via Napoli 9, Bari', 'destination_zone' => 'S',
        'weight_kg' => '80', 'volume_cbm' => '2', 'distance_km' => '420', ...$overrides,
    ]);
}

/**
 * A planned order of the carrier, optionally with an explicit expected cost.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function tmsPlannedOrder(string $carrierId, array $overrides = [], ?string $cost = null): array
{
    $order = tmsOrder($overrides)->assertCreated()->json('data');
    test()->postJson('/api/v1/transportation/transport-orders/'.$order['id'].'/plan')->assertOk();

    return test()->postJson('/api/v1/transportation/transport-orders/'.$order['id'].'/assign-carrier', ['carrier_id' => $carrierId, ...($cost !== null ? ['expected_cost' => $cost] : [])])->assertOk()->json('data');
}

test('carriers are unique per company, filtered and kept while they have open work', function (): void {
    $carrier = tmsCarrier('dhl', ['mode' => 'courier', 'service_level' => 'express']);
    expect($carrier['code'])->toBe('DHL')->and($carrier['currency'])->toBe('EUR')->and($carrier['is_active'])->toBeTrue();

    $this->postJson('/api/v1/transportation/carriers', ['code' => 'dhl', 'name' => 'Again'])->assertUnprocessable()->assertJsonValidationErrors(['code']);
    $this->postJson('/api/v1/transportation/carriers', ['code' => 'X', 'name' => 'X', 'mode' => 'teleport'])->assertUnprocessable()->assertJsonValidationErrors(['mode']);
    $this->postJson('/api/v1/transportation/carriers', ['code' => 'X', 'name' => 'X', 'currency' => 'EURO'])->assertUnprocessable()->assertJsonValidationErrors(['currency']);
    $this->putJson('/api/v1/transportation/carriers/'.$carrier['id'], ['name' => 'DHL Express', 'is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
    $this->putJson('/api/v1/transportation/carriers/'.$carrier['id'], ['code' => 'dhl'])->assertOk();

    $this->getJson('/api/v1/transportation/carriers?mode=courier&is_active=0&search=express')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/transportation/carriers?mode=road')->assertOk()->assertJsonCount(0, 'data');

    $this->putJson('/api/v1/transportation/carriers/'.$carrier['id'], ['is_active' => true])->assertOk();
    $this->putJson('/api/v1/transportation/carriers/'.$carrier['id'], ['is_active' => true])->assertOk();
    tmsPlannedOrder($carrier['id'], [], '10.00');
    $this->deleteJson('/api/v1/transportation/carriers/'.$carrier['id'])->assertUnprocessable()->assertJsonValidationErrors(['id']);
    $free = tmsCarrier('free');
    $this->deleteJson('/api/v1/transportation/carriers/'.$free['id'])->assertNoContent();
});

test('records stay inside the company, a foreign carrier is refused and a read-only member never writes', function (): void {
    $carrier = tmsCarrier('mine');
    $order = tmsOrder()->assertCreated()->json('data');

    $other = tmsTenant('Altra');
    tmsAct(tmsMember($other, 'owner'), $other);
    $this->getJson('/api/v1/transportation/carriers/'.$carrier['id'])->assertNotFound();
    $this->getJson('/api/v1/transportation/transport-orders/'.$order['id'])->assertNotFound();
    $this->getJson('/api/v1/transportation/transport-orders')->assertOk()->assertJsonCount(0, 'data');
    $this->postJson('/api/v1/transportation/transport-orders/'.$order['id'].'/plan')->assertNotFound();
    $this->postJson('/api/v1/transportation/tariff-tables', ['carrier_id' => $carrier['id'], 'name' => 'x', 'valid_from' => '2027-01-01'])->assertUnprocessable()->assertJsonValidationErrors(['carrier_id']);

    tmsAct($this->owner, $this->tenant);
    $this->postJson('/api/v1/transportation/transport-orders/'.$order['id'].'/assign-carrier', ['carrier_id' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors(['carrier_id']);

    tmsAct(tmsMember($this->tenant, 'accountant_readonly'), $this->tenant);
    $this->getJson('/api/v1/transportation/carriers')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/transportation/dashboard')->assertOk();
    $this->postJson('/api/v1/transportation/carriers', ['code' => 'RO', 'name' => 'RO'])->assertForbidden();
    $this->postJson('/api/v1/transportation/transport-orders/'.$order['id'].'/plan')->assertForbidden();
    $this->postJson('/api/v1/transportation/quotes', ['weight_kg' => '10'])->assertForbidden();
    $this->deleteJson('/api/v1/transportation/carriers/'.$carrier['id'])->assertForbidden();
});

test('tariff bands never overlap for the same zones and their ranges are ordered', function (): void {
    $carrier = tmsCarrier('alpha');
    $table = tmsTable($carrier['id']);
    $this->postJson('/api/v1/transportation/tariff-tables', ['carrier_id' => $carrier['id'], 'name' => 'Bad', 'valid_from' => '2027-05-01', 'valid_to' => '2027-04-01'])->assertUnprocessable()->assertJsonValidationErrors(['valid_to']);
    $this->postJson('/api/v1/transportation/tariff-tables', ['carrier_id' => $carrier['id'], 'name' => 'Bad', 'valid_from' => '2027-05-01', 'rate_basis' => 'telepathy'])->assertUnprocessable()->assertJsonValidationErrors(['rate_basis']);
    $this->postJson('/api/v1/transportation/tariff-tables', ['carrier_id' => $carrier['id'], 'name' => 'Bad', 'valid_from' => '2027-05-01', 'fuel_surcharge_percent' => '120'])->assertUnprocessable()->assertJsonValidationErrors(['fuel_surcharge_percent']);

    $first = tmsBand($table['id'], ['destination_zone' => 'S', 'weight_from_kg' => '0', 'weight_to_kg' => '100', 'fixed_amount' => '30'])->assertCreated()->assertJsonPath('data.weight_to_kg', '100.000')->json('data');
    $second = tmsBand($table['id'], ['destination_zone' => 'S', 'weight_from_kg' => '100'])->assertCreated()->assertJsonPath('data.weight_to_kg', null)->json('data');

    tmsBand($table['id'], ['destination_zone' => 'S', 'weight_from_kg' => '50', 'weight_to_kg' => '150'])->assertUnprocessable()->assertJsonValidationErrors(['weight_from_kg']);
    tmsBand($table['id'], ['destination_zone' => 'S', 'weight_from_kg' => '500'])->assertUnprocessable()->assertJsonValidationErrors(['weight_from_kg']);
    tmsBand($table['id'], ['destination_zone' => 'S', 'weight_from_kg' => '10', 'weight_to_kg' => '10'])->assertUnprocessable()->assertJsonValidationErrors(['weight_to_kg']);
    tmsBand($table['id'], ['destination_zone' => 'S', 'distance_from_km' => '10', 'distance_to_km' => '5'])->assertUnprocessable()->assertJsonValidationErrors(['distance_to_km']);
    // Other zones, or a distinct distance range, do not clash.
    tmsBand($table['id'], ['destination_zone' => 'E', 'weight_from_kg' => '50', 'weight_to_kg' => '150'])->assertCreated();
    tmsBand($table['id'], ['destination_zone' => 'S', 'weight_from_kg' => '0', 'weight_to_kg' => '100', 'distance_from_km' => '100'])->assertUnprocessable()->assertJsonValidationErrors(['weight_from_kg']);

    $this->putJson('/api/v1/transportation/tariff-bands/'.$first['id'], ['weight_to_kg' => '120'])->assertUnprocessable()->assertJsonValidationErrors(['weight_from_kg']);
    $this->putJson('/api/v1/transportation/tariff-bands/'.$first['id'], ['fixed_amount' => '35'])->assertOk()->assertJsonPath('data.fixed_amount', '35.00');
    $this->getJson('/api/v1/transportation/tariff-bands?tariff_table_id='.$table['id'].'&destination_zone=S')->assertOk()->assertJsonCount(2, 'data');

    $this->deleteJson('/api/v1/transportation/tariff-bands/'.$second['id'])->assertNoContent();
    $this->deleteJson('/api/v1/transportation/tariff-tables/'.$table['id'])->assertNoContent();
    $this->getJson('/api/v1/transportation/tariff-bands/'.$first['id'])->assertNotFound();
});

test('rates are exact: the most specific band, minimum charge, fuel surcharge and half-up rounding', function (): void {
    ['alpha' => $alpha, 'beta' => $beta, 'gamma' => $gamma] = tmsTariffs();
    $rate = fn (array $carrier, array $shipment): TestResponse => $this->postJson('/api/v1/transportation/rates/calculate', ['carrier_id' => $carrier['id'], 'date' => '2027-06-15', ...$shipment]);

    $rate($alpha, ['weight_kg' => '80', 'origin_zone' => 'N', 'destination_zone' => 'S'])->assertOk()->assertJsonPath('data.fixed_amount', '30.00')->assertJsonPath('data.weight_amount', '40.00')
        ->assertJsonPath('data.base_amount', '70.00')->assertJsonPath('data.fuel_surcharge', '7.00')->assertJsonPath('data.total_amount', '77.00')->assertJsonPath('data.minimum_applied', false);
    // The second band starts at 100 kg: 20 + 0.4 x 250 = 120, plus 10 percent fuel.
    $rate($alpha, ['weight_kg' => '250', 'origin_zone' => 'N', 'destination_zone' => 'S'])->assertOk()->assertJsonPath('data.base_amount', '120.00')->assertJsonPath('data.total_amount', '132.00');
    $rate($alpha, ['weight_kg' => '100', 'origin_zone' => 'N', 'destination_zone' => 'S'])->assertOk()->assertJsonPath('data.base_amount', '60.00');
    // 30 + 0.5 x 10 = 35 is below the minimum charge of 50.
    $rate($alpha, ['weight_kg' => '10', 'origin_zone' => 'N', 'destination_zone' => 'S'])->assertOk()->assertJsonPath('data.minimum_applied', true)->assertJsonPath('data.base_amount', '50.00')->assertJsonPath('data.total_amount', '55.00');
    $rate($beta, ['weight_kg' => '80', 'distance_km' => '420'])->assertOk()->assertJsonPath('data.distance_amount', '126.00')->assertJsonPath('data.total_amount', '136.00')->assertJsonPath('data.weight_amount', '0.00');
    // 5 + 16 + 42 = 63, fuel 1.575 rounds to 1.58.
    $rate($gamma, ['weight_kg' => '80', 'distance_km' => '420'])->assertOk()->assertJsonPath('data.base_amount', '63.00')->assertJsonPath('data.fuel_surcharge', '1.58')->assertJsonPath('data.total_amount', '64.58');

    // No band: wrong zones, out of the distance range, distance missing for a distance tariff, before the table is valid.
    $rate($alpha, ['weight_kg' => '80', 'origin_zone' => 'S', 'destination_zone' => 'N'])->assertUnprocessable()->assertJsonValidationErrors(['carrier_id']);
    $rate($beta, ['weight_kg' => '80', 'distance_km' => '500'])->assertUnprocessable()->assertJsonValidationErrors(['carrier_id']);
    $rate($beta, ['weight_kg' => '80'])->assertUnprocessable()->assertJsonValidationErrors(['carrier_id']);
    $rate($alpha, ['weight_kg' => '80', 'origin_zone' => 'N', 'destination_zone' => 'S', 'date' => '2027-03-31'])->assertUnprocessable()->assertJsonValidationErrors(['carrier_id']);
    $rate($alpha, ['weight_kg' => '0'])->assertUnprocessable()->assertJsonValidationErrors(['weight_kg']);

    // A deactivated tariff table prices nothing; a newer table wins over an older one.
    $tables = $this->getJson('/api/v1/transportation/tariff-tables?carrier_id='.$gamma['id'])->assertOk()->json('data');
    $this->putJson('/api/v1/transportation/tariff-tables/'.$tables[0]['id'], ['is_active' => false])->assertOk();
    $rate($gamma, ['weight_kg' => '80', 'distance_km' => '420'])->assertUnprocessable();
    $this->putJson('/api/v1/transportation/tariff-tables/'.$tables[0]['id'], ['is_active' => true]);
    $newer = tmsTable($gamma['id'], ['valid_from' => '2027-06-01', 'rate_basis' => 'flat']);
    tmsBand($newer['id'], ['fixed_amount' => '99.99'])->assertCreated();
    $rate($gamma, ['weight_kg' => '80', 'distance_km' => '420'])->assertOk()->assertJsonPath('data.total_amount', '99.99')->assertJsonPath('data.tariff_table_id', $newer['id']);
});

test('a quote ranks the carriers cheapest first and awarding it gives the carrier and cost to the order', function (): void {
    ['alpha' => $alpha, 'beta' => $beta, 'gamma' => $gamma] = tmsTariffs();
    $inactive = tmsCarrier('delta');
    $deltaTable = tmsTable($inactive['id'], ['valid_from' => '2027-01-01', 'rate_basis' => 'flat']);
    tmsBand($deltaTable['id'], ['fixed_amount' => '1'])->assertCreated();
    $this->putJson('/api/v1/transportation/carriers/'.$inactive['id'], ['is_active' => false])->assertOk();
    tmsCarrier('epsilon');

    $shipment = ['weight_kg' => '80', 'distance_km' => '420', 'origin_zone' => 'N', 'destination_zone' => 'S', 'ship_date' => '2027-06-15'];
    $this->postJson('/api/v1/transportation/quotes', [])->assertUnprocessable()->assertJsonValidationErrors(['weight_kg']);
    $this->postJson('/api/v1/transportation/quotes', [...$shipment, 'destination_zone' => 'Z', 'distance_km' => null, 'origin_zone' => 'Q'])->assertUnprocessable()->assertJsonValidationErrors(['weight_kg']);

    $quote = $this->postJson('/api/v1/transportation/quotes', [...$shipment, 'reference' => 'RFQ-1'])->assertCreated()->assertJsonPath('data.status', 'open')->assertJsonCount(3, 'data.offers')->json('data');
    expect(collect($quote['offers'])->pluck('carrier_name')->all())->toBe(['Gamma Logistics', 'Alpha Logistics', 'Beta Logistics'])
        ->and(collect($quote['offers'])->pluck('total_amount')->all())->toBe(['64.58', '77.00', '136.00'])
        ->and(collect($quote['offers'])->pluck('rank')->all())->toBe([1, 2, 3]);

    // Before April the Alpha table is not valid yet: only Beta and Gamma answer.
    $early = $this->postJson('/api/v1/transportation/quotes', [...$shipment, 'ship_date' => '2027-02-01'])->assertCreated()->assertJsonCount(2, 'data.offers')->json('data');
    expect(collect($early['offers'])->pluck('carrier_id')->sort()->values()->all())->toBe(collect([$beta['id'], $gamma['id']])->sort()->values()->all());
    $this->postJson('/api/v1/transportation/quotes', [...$shipment, 'currency' => 'CHF'])->assertUnprocessable()->assertJsonValidationErrors(['weight_kg']);

    $this->postJson('/api/v1/transportation/quotes/'.$quote['id'].'/award', ['carrier_id' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors(['carrier_id']);
    $this->postJson('/api/v1/transportation/quotes/'.$early['id'].'/award', ['carrier_id' => $alpha['id']])->assertUnprocessable()->assertJsonValidationErrors(['carrier_id']);
    $this->postJson('/api/v1/transportation/quotes/'.$quote['id'].'/award', ['carrier_id' => $alpha['id']])->assertOk()
        ->assertJsonPath('data.status', 'awarded')->assertJsonPath('data.awarded_carrier_id', $alpha['id'])->assertJsonPath('data.awarded_amount', '77.00');
    $this->postJson('/api/v1/transportation/quotes/'.$quote['id'].'/award', ['carrier_id' => $beta['id']])->assertUnprocessable()->assertJsonValidationErrors(['status']);
    $this->deleteJson('/api/v1/transportation/quotes/'.$quote['id'])->assertUnprocessable()->assertJsonValidationErrors(['status']);
    $this->deleteJson('/api/v1/transportation/quotes/'.$early['id'])->assertNoContent();
    $this->getJson('/api/v1/transportation/quotes?status=awarded')->assertOk()->assertJsonCount(1, 'data');

    // A quote linked to an order takes its data and, once awarded, assigns the carrier and the cost.
    $order = tmsOrder(['pickup_from' => '2027-06-15T08:00:00+00:00'])->assertCreated()->json('data');
    $linked = $this->postJson('/api/v1/transportation/quotes', ['transport_order_id' => $order['id']])->assertCreated()->assertJsonPath('data.weight_kg', '80.000')->assertJsonPath('data.ship_date', '2027-06-15')->assertJsonPath('data.offers.0.total_amount', '64.58')->json('data');
    $this->postJson('/api/v1/transportation/quotes/'.$linked['id'].'/award', ['carrier_id' => $gamma['id']])->assertOk();
    $this->getJson('/api/v1/transportation/transport-orders/'.$order['id'])->assertOk()->assertJsonPath('data.carrier.id', $gamma['id'])->assertJsonPath('data.expected_cost', '64.58');
});

test('a transport order is numbered, validated and follows its state machine', function (): void {
    ['alpha' => $alpha, 'beta' => $beta] = tmsTariffs();

    tmsOrder(['weight_kg' => '0'])->assertUnprocessable()->assertJsonValidationErrors(['weight_kg']);
    tmsOrder(['delivery_from' => '2027-06-17T10:00:00+00:00', 'delivery_to' => '2027-06-16T10:00:00+00:00'])->assertUnprocessable()->assertJsonValidationErrors(['delivery_to']);
    tmsOrder(['origin_name' => null])->assertUnprocessable()->assertJsonValidationErrors(['origin_name']);

    $order = tmsOrder(['reference' => 'SO-1', 'delivery_to' => '2027-06-16T12:00:00+00:00'])->assertCreated()->assertJsonPath('data.number', 'TO-2027-0001')->assertJsonPath('data.status', 'draft')->assertJsonPath('data.is_on_time', null)->json('data');
    $this->postJson('/api/v1/transportation/transport-orders', [])->assertUnprocessable();
    tmsOrder()->assertCreated()->assertJsonPath('data.number', 'TO-2027-0002');
    $id = $order['id'];

    // A draft takes no events and no trip; assigning a carrier computes the expected cost.
    $this->postJson('/api/v1/transportation/tracking-events', ['transport_order_id' => $id, 'event_type' => 'picked_up', 'occurred_at' => '2027-06-15T09:00:00+00:00'])->assertUnprocessable()->assertJsonValidationErrors(['transport_order_id']);
    $this->postJson("/api/v1/transportation/transport-orders/$id/assign-carrier", ['carrier_id' => $beta['id'], 'expected_cost' => '-1'])->assertUnprocessable()->assertJsonValidationErrors(['expected_cost']);
    $this->postJson("/api/v1/transportation/transport-orders/$id/assign-carrier", ['carrier_id' => $alpha['id']])->assertOk()->assertJsonPath('data.expected_cost', '77.00')->assertJsonPath('data.carrier.code', 'ALPHA');
    $this->postJson("/api/v1/transportation/transport-orders/$id/assign-carrier", ['carrier_id' => $beta['id'], 'expected_cost' => '140.5'])->assertOk()->assertJsonPath('data.expected_cost', '140.50');
    $this->putJson("/api/v1/transportation/transport-orders/$id", ['pallets' => 3, 'goods_description' => 'Tiles'])->assertOk()->assertJsonPath('data.pallets', 3);

    $this->postJson("/api/v1/transportation/transport-orders/$id/plan")->assertOk()->assertJsonPath('data.status', 'planned');
    $this->postJson("/api/v1/transportation/transport-orders/$id/plan")->assertUnprocessable()->assertJsonValidationErrors(['status']);
    $this->deleteJson("/api/v1/transportation/transport-orders/$id")->assertUnprocessable();

    $event = static fn (string $type, string $at, array $extra = []): TestResponse => test()->postJson('/api/v1/transportation/tracking-events', ['transport_order_id' => test()->order_id, 'event_type' => $type, 'occurred_at' => $at, 'location' => 'Hub Bologna', ...$extra]);
    $this->order_id = $id;
    $event('picked_up', '2027-06-15T10:00:00+00:00')->assertCreated()->assertJsonPath('data.event_type', 'picked_up');
    $this->getJson("/api/v1/transportation/transport-orders/$id")->assertOk()->assertJsonPath('data.status', 'in_transit');
    $event('delay', '2027-06-15T18:00:00+00:00', ['notes' => 'Traffic'])->assertCreated();
    $this->getJson("/api/v1/transportation/transport-orders/$id")->assertOk()->assertJsonPath('data.status', 'in_transit');
    $event('exception', '2027-06-15T20:00:00+00:00', ['notes' => 'Damaged pallet'])->assertCreated();
    $this->getJson("/api/v1/transportation/transport-orders/$id")->assertOk()->assertJsonPath('data.status', 'exception');
    $event('out_for_delivery', '2027-06-16T08:00:00+00:00')->assertCreated();
    $this->getJson("/api/v1/transportation/transport-orders/$id")->assertOk()->assertJsonPath('data.status', 'in_transit');
    $this->postJson("/api/v1/transportation/transport-orders/$id/cancel")->assertUnprocessable();
    $this->putJson("/api/v1/transportation/transport-orders/$id", ['pallets' => 1])->assertUnprocessable()->assertJsonValidationErrors(['status']);
    $event('bad', '2027-06-16T09:00:00+00:00')->assertUnprocessable()->assertJsonValidationErrors(['event_type']);

    $event('delivered', '2027-06-16T11:30:00+00:00')->assertCreated();
    $this->getJson("/api/v1/transportation/transport-orders/$id")->assertOk()->assertJsonPath('data.status', 'delivered')->assertJsonPath('data.delivered_at', '2027-06-16T11:30:00+00:00')->assertJsonPath('data.is_on_time', true);
    $event('delay', '2027-06-16T12:00:00+00:00')->assertUnprocessable()->assertJsonValidationErrors(['transport_order_id']);

    $this->getJson("/api/v1/transportation/tracking-events?transport_order_id=$id")->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('data.0.event_type', 'delivered');
    $this->getJson("/api/v1/transportation/tracking-events?transport_order_id=$id&event_type=delay&from=2027-06-15&to=2027-06-15")->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/transportation/transport-orders?status=delivered&search=so-1')->assertOk()->assertJsonCount(1, 'data');

    // A late delivery is not on time; a cancelled order is closed for good.
    $late = tmsPlannedOrder($alpha['id'], ['delivery_to' => '2027-06-16T10:00:00+00:00']);
    $this->order_id = $late['id'];
    $event('delivered', '2027-06-16T10:00:01+00:00')->assertCreated();
    $this->getJson('/api/v1/transportation/transport-orders/'.$late['id'])->assertOk()->assertJsonPath('data.is_on_time', false);
    $cancelled = tmsOrder()->assertCreated()->json('data');
    $this->postJson('/api/v1/transportation/transport-orders/'.$cancelled['id'].'/cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');
    $this->deleteJson('/api/v1/transportation/transport-orders/'.$cancelled['id'])->assertNoContent();
});

test('a trip consolidates planned orders within its capacity and orders their stops', function (): void {
    $alpha = tmsCarrier('alpha');
    $other = tmsCarrier('other');
    $vehicle = FleetVehicle::factory()->create(['tenant_id' => $this->tenant->id]);
    $foreignVehicle = FleetVehicle::factory()->create();

    $this->postJson('/api/v1/transportation/trips', ['vehicle_id' => $foreignVehicle->id])->assertUnprocessable()->assertJsonValidationErrors(['vehicle_id']);
    $this->postJson('/api/v1/transportation/trips', ['planned_start_at' => '2027-06-16T08:00:00+00:00', 'planned_end_at' => '2027-06-16T07:00:00+00:00'])->assertUnprocessable()->assertJsonValidationErrors(['planned_end_at']);
    $this->postJson('/api/v1/transportation/trips', ['capacity_weight_kg' => '0'])->assertUnprocessable()->assertJsonValidationErrors(['capacity_weight_kg']);
    $trip = $this->postJson('/api/v1/transportation/trips', ['carrier_id' => $alpha['id'], 'vehicle_id' => $vehicle->id, 'capacity_weight_kg' => '200', 'capacity_volume_cbm' => '10', 'planned_distance_km' => '420'])
        ->assertCreated()->assertJsonPath('data.number', 'TR-2027-0001')->assertJsonPath('data.status', 'planned')->assertJsonPath('data.total_weight_kg', '0.000')->json('data');
    $id = $trip['id'];

    $one = tmsPlannedOrder($alpha['id'], ['weight_kg' => '80', 'volume_cbm' => '2'], '70.00');
    $two = tmsPlannedOrder($alpha['id'], ['weight_kg' => '90', 'volume_cbm' => '3'], '80.00');
    $three = tmsPlannedOrder($alpha['id'], ['weight_kg' => '50', 'volume_cbm' => '1'], '40.00');
    $draft = tmsOrder()->assertCreated()->json('data');
    $foreign = tmsPlannedOrder($other['id'], [], '10.00');

    $this->postJson("/api/v1/transportation/trips/$id/orders", ['order_ids' => []])->assertUnprocessable()->assertJsonValidationErrors(['order_ids']);
    $this->postJson("/api/v1/transportation/trips/$id/orders", ['order_ids' => [(string) Str::uuid()]])->assertUnprocessable()->assertJsonValidationErrors(['order_ids']);
    $this->postJson("/api/v1/transportation/trips/$id/orders", ['order_ids' => [$draft['id']]])->assertUnprocessable()->assertJsonValidationErrors(['order_ids']);
    $this->postJson("/api/v1/transportation/trips/$id/orders", ['order_ids' => [$foreign['id']]])->assertUnprocessable()->assertJsonValidationErrors(['order_ids']);

    $loaded = $this->postJson("/api/v1/transportation/trips/$id/orders", ['order_ids' => [$one['id'], $two['id']]])->assertOk()
        ->assertJsonPath('data.total_weight_kg', '170.000')->assertJsonPath('data.total_volume_cbm', '5.000')->assertJsonPath('data.utilization_percent', '85.00')->assertJsonCount(4, 'data.stops')->json('data');
    // Pickups first, then the deliveries, in the order the orders were added.
    expect(collect($loaded['stops'])->map(fn (array $stop): string => $stop['stop_type'].':'.$stop['order_number'])->all())
        ->toBe(['pickup:'.$one['number'], 'pickup:'.$two['number'], 'delivery:'.$one['number'], 'delivery:'.$two['number']])
        ->and(collect($loaded['stops'])->pluck('sequence')->all())->toBe([1, 2, 3, 4]);

    $this->postJson("/api/v1/transportation/trips/$id/orders", ['order_ids' => [$three['id']]])->assertUnprocessable()->assertJsonValidationErrors(['order_ids']);
    $this->postJson("/api/v1/transportation/trips/$id/orders", ['order_ids' => [$one['id']]])->assertUnprocessable()->assertJsonValidationErrors(['order_ids']);
    $this->putJson("/api/v1/transportation/trips/$id", ['capacity_weight_kg' => '100'])->assertUnprocessable()->assertJsonValidationErrors(['capacity_weight_kg']);
    $this->putJson("/api/v1/transportation/trips/$id", ['capacity_volume_cbm' => '4'])->assertUnprocessable()->assertJsonValidationErrors(['capacity_volume_cbm']);
    $this->deleteJson("/api/v1/transportation/trips/$id")->assertUnprocessable();
    $this->postJson("/api/v1/transportation/transport-orders/{$one['id']}/cancel")->assertUnprocessable()->assertJsonValidationErrors(['status']);
    $this->putJson("/api/v1/transportation/trips/$id", ['capacity_weight_kg' => '300', 'notes' => 'Bigger truck'])->assertOk()->assertJsonPath('data.capacity_weight_kg', '300.000');
    $this->postJson("/api/v1/transportation/trips/$id/orders", ['order_ids' => [$three['id']]])->assertOk()->assertJsonPath('data.total_weight_kg', '220.000')->assertJsonCount(6, 'data.stops');
    $this->postJson("/api/v1/transportation/trips/$id/orders", ['order_ids' => [$one['id']]])->assertUnprocessable();

    // Take an order off: its stops go, the totals follow, it is free again.
    $this->deleteJson("/api/v1/transportation/trips/$id/orders/{$three['id']}")->assertOk()->assertJsonPath('data.total_weight_kg', '170.000')->assertJsonCount(4, 'data.stops');
    $this->deleteJson("/api/v1/transportation/trips/$id/orders/{$three['id']}")->assertUnprocessable()->assertJsonValidationErrors(['order_id']);
    $this->getJson("/api/v1/transportation/transport-orders/{$three['id']}")->assertOk()->assertJsonPath('data.trip', null);

    // Reorder: all stops once, a pickup before its delivery.
    $stops = collect($loaded['stops'])->pluck('id', 'sequence');
    $this->putJson("/api/v1/transportation/trips/$id/stops/sequence", ['stop_ids' => [$stops[1], $stops[2], $stops[3]]])->assertUnprocessable()->assertJsonValidationErrors(['stop_ids']);
    $this->putJson("/api/v1/transportation/trips/$id/stops/sequence", ['stop_ids' => [$stops[3], $stops[1], $stops[2], $stops[4]]])->assertUnprocessable()->assertJsonValidationErrors(['stop_ids']);
    $reordered = $this->putJson("/api/v1/transportation/trips/$id/stops/sequence", ['stop_ids' => [$stops[1], $stops[2], $stops[4], $stops[3]]])->assertOk()->json('data.stops');
    expect(collect($reordered)->pluck('id')->all())->toBe([$stops[1], $stops[2], $stops[4], $stops[3]]);

    $this->getJson('/api/v1/transportation/trips?status=planned&carrier_id='.$alpha['id'])->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/transportation/trips/$id")->assertOk()->assertJsonPath('data.carrier.code', 'ALPHA')->assertJsonCount(4, 'data.stops');
});

test('a trip is dispatched, run stop by stop and completed, delivering its orders', function (): void {
    $carrier = tmsCarrier('alpha');
    $trip = $this->postJson('/api/v1/transportation/trips', ['carrier_id' => $carrier['id'], 'capacity_weight_kg' => '500', 'planned_distance_km' => '100'])->assertCreated()->json('data');
    $id = $trip['id'];
    $order = tmsPlannedOrder($carrier['id'], ['weight_kg' => '80', 'delivery_to' => '2027-06-16T12:00:00+00:00'], '70.00');

    $this->postJson("/api/v1/transportation/trips/$id/dispatch")->assertUnprocessable()->assertJsonValidationErrors(['status']);
    $stops = $this->postJson("/api/v1/transportation/trips/$id/orders", ['order_ids' => [$order['id']]])->assertOk()->json('data.stops');
    $this->postJson("/api/v1/transportation/trips/$id/start")->assertUnprocessable();
    $this->postJson("/api/v1/transportation/trips/$id/stops/{$stops[0]['id']}/complete")->assertUnprocessable()->assertJsonValidationErrors(['status']);
    $this->postJson("/api/v1/transportation/trips/$id/dispatch")->assertOk()->assertJsonPath('data.status', 'dispatched');
    $this->postJson("/api/v1/transportation/trips/$id/complete")->assertUnprocessable();
    $this->postJson("/api/v1/transportation/trips/$id/start")->assertOk()->assertJsonPath('data.status', 'in_progress')->assertJsonPath('data.actual_start_at', now()->toIso8601String());
    $this->postJson("/api/v1/transportation/trips/$id/orders", ['order_ids' => [$order['id']]])->assertUnprocessable();
    $this->postJson("/api/v1/transportation/trips/$id/cancel")->assertUnprocessable();
    $this->putJson("/api/v1/transportation/trips/$id", ['notes' => 'x'])->assertUnprocessable()->assertJsonValidationErrors(['status']);

    $this->postJson("/api/v1/transportation/trips/$id/stops/{$stops[1]['id']}/complete")->assertUnprocessable()->assertJsonValidationErrors(['stop_id']);
    $this->postJson("/api/v1/transportation/trips/$id/stops/{$stops[0]['id']}/complete", ['arrived_at' => '2027-06-15T10:00:00+00:00', 'departed_at' => '2027-06-15T09:00:00+00:00'])->assertUnprocessable()->assertJsonValidationErrors(['departed_at']);
    $this->postJson("/api/v1/transportation/trips/$id/stops/{$stops[0]['id']}/complete", ['arrived_at' => '2027-06-15T10:00:00+00:00', 'departed_at' => '2027-06-15T10:20:00+00:00'])->assertOk()
        ->assertJsonPath('data.stops.0.status', 'completed')->assertJsonPath('data.stops.0.actual_departure_at', '2027-06-15T10:20:00+00:00');
    $this->getJson('/api/v1/transportation/transport-orders/'.$order['id'])->assertOk()->assertJsonPath('data.status', 'in_transit');
    $this->postJson("/api/v1/transportation/trips/$id/stops/{$stops[0]['id']}/complete")->assertUnprocessable()->assertJsonValidationErrors(['stop_id']);
    $this->postJson("/api/v1/transportation/trips/$id/complete")->assertUnprocessable()->assertJsonValidationErrors(['status']);

    $this->postJson("/api/v1/transportation/trips/$id/stops/{$stops[1]['id']}/complete", ['arrived_at' => '2027-06-16T11:00:00+00:00'])->assertOk()->assertJsonPath('data.stops.1.actual_departure_at', '2027-06-16T11:00:00+00:00');
    $this->getJson('/api/v1/transportation/transport-orders/'.$order['id'])->assertOk()->assertJsonPath('data.status', 'delivered')->assertJsonPath('data.is_on_time', true);

    $this->postJson("/api/v1/transportation/trips/$id/complete", ['actual_distance_km' => '0'])->assertUnprocessable()->assertJsonValidationErrors(['actual_distance_km']);
    $this->postJson("/api/v1/transportation/trips/$id/complete", ['actual_cost' => '150'])->assertOk()
        ->assertJsonPath('data.status', 'completed')->assertJsonPath('data.actual_distance_km', '100.00')->assertJsonPath('data.actual_cost', '150.00');
    $this->deleteJson("/api/v1/transportation/trips/$id")->assertUnprocessable();
    $this->getJson('/api/v1/transportation/dashboard')->assertOk()->assertJsonPath('data.cost_per_km.value', '1.5000')->assertJsonPath('data.cost_per_km.trips', 1);
});

test('a cancelled trip frees its orders and a planned empty trip can be deleted', function (): void {
    $carrier = tmsCarrier('alpha');
    $trip = $this->postJson('/api/v1/transportation/trips', ['carrier_id' => $carrier['id']])->assertCreated()->json('data');
    $order = tmsPlannedOrder($carrier['id'], [], '10.00');
    $this->postJson('/api/v1/transportation/trips/'.$trip['id'].'/orders', ['order_ids' => [$order['id']]])->assertOk();

    $this->postJson('/api/v1/transportation/trips/'.$trip['id'].'/cancel')->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.total_weight_kg', '0.000')->assertJsonCount(0, 'data.stops');
    $this->getJson('/api/v1/transportation/transport-orders/'.$order['id'])->assertOk()->assertJsonPath('data.status', 'planned')->assertJsonPath('data.trip', null);

    $empty = $this->postJson('/api/v1/transportation/trips', [])->assertCreated()->assertJsonPath('data.number', 'TR-2027-0002')->json('data');
    $this->deleteJson('/api/v1/transportation/trips/'.$empty['id'])->assertNoContent();
    expect(TmsTrip::query()->count())->toBe(1);
});

test('the freight audit compares every line with the expected rate and flags over billing, under billing and unmatched lines', function (): void {
    ['alpha' => $alpha, 'beta' => $beta] = tmsTariffs();
    $a = tmsPlannedOrder($alpha['id'], ['weight_kg' => '80']);
    $b = tmsPlannedOrder($alpha['id'], ['weight_kg' => '250']);
    $c = tmsPlannedOrder($alpha['id'], ['weight_kg' => '120']);
    $foreign = tmsPlannedOrder($beta['id'], ['weight_kg' => '80', 'distance_km' => '420']);
    expect([$a['expected_cost'], $b['expected_cost'], $c['expected_cost']])->toBe(['77.00', '132.00', '74.80']);

    $payload = ['carrier_id' => $alpha['id'], 'invoice_number' => 'FT-100', 'invoice_date' => '2027-06-30', 'lines' => [
        ['transport_order_id' => $a['id'], 'billed_amount' => '78'],
        ['transport_order_id' => $b['id'], 'billed_amount' => '140', 'description' => 'Heavy pallet'],
        ['billed_amount' => '20', 'description' => 'Waiting time'],
    ]];

    $this->postJson('/api/v1/transportation/freight-invoices', [...$payload, 'lines' => []])->assertUnprocessable()->assertJsonValidationErrors(['lines']);
    $this->postJson('/api/v1/transportation/freight-invoices', [...$payload, 'lines' => [['billed_amount' => '-1']]])->assertUnprocessable()->assertJsonValidationErrors(['lines.0.billed_amount']);
    $this->postJson('/api/v1/transportation/freight-invoices', [...$payload, 'lines' => [['transport_order_id' => $foreign['id'], 'billed_amount' => '10']]])->assertUnprocessable()->assertJsonValidationErrors(['lines.0.transport_order_id']);
    $invoice = $this->postJson('/api/v1/transportation/freight-invoices', $payload)->assertCreated()
        ->assertJsonPath('data.total_amount', '238.00')->assertJsonPath('data.audit_status', 'pending')->assertJsonPath('data.dispute_status', 'none')->assertJsonCount(3, 'data.lines')->assertJsonPath('data.lines.0.order_number', $a['number'])->json('data');
    $this->postJson('/api/v1/transportation/freight-invoices', $payload)->assertUnprocessable()->assertJsonValidationErrors(['invoice_number']);
    $this->postJson('/api/v1/transportation/freight-invoices', ['carrier_id' => $beta['id'], 'invoice_number' => 'FT-100', 'invoice_date' => '2027-06-30', 'lines' => [['billed_amount' => '5']]])->assertCreated();
    $id = $invoice['id'];

    $this->postJson("/api/v1/transportation/freight-invoices/$id/audit", ['tolerance_percent' => '101'])->assertUnprocessable()->assertJsonValidationErrors(['tolerance_percent']);
    $audited = $this->postJson("/api/v1/transportation/freight-invoices/$id/audit")->assertOk()
        ->assertJsonPath('data.audit_status', 'over_billed')->assertJsonPath('data.expected_total', '209.00')->assertJsonPath('data.variance_amount', '9.00')->assertJsonPath('data.tolerance_percent', '2.00')->json('data');
    $byAmount = collect($audited['lines'])->keyBy('billed_amount');
    expect($byAmount['78.00'])->toMatchArray(['expected_amount' => '77.00', 'variance_amount' => '1.00', 'variance_percent' => '1.30', 'audit_status' => 'matched'])
        ->and($byAmount['140.00'])->toMatchArray(['expected_amount' => '132.00', 'variance_amount' => '8.00', 'variance_percent' => '6.06', 'audit_status' => 'over_billed'])
        ->and($byAmount['20.00'])->toMatchArray(['expected_amount' => null, 'variance_amount' => null, 'audit_status' => 'unmatched']);
    // The billed amount becomes the actual cost of the order.
    $this->getJson('/api/v1/transportation/transport-orders/'.$a['id'])->assertOk()->assertJsonPath('data.actual_cost', '78.00');

    // With a wide tolerance the heavy line matches too; the line without an order stays unmatched.
    $this->postJson("/api/v1/transportation/freight-invoices/$id/audit", ['tolerance_percent' => '10'])->assertOk()->assertJsonPath('data.audit_status', 'unmatched')->assertJsonPath('data.tolerance_percent', '10.00');
    // Under billing is flagged too; an order without a stored cost is priced from the tariff tables.
    $this->putJson("/api/v1/transportation/freight-invoices/$id", ['lines' => [['transport_order_id' => $c['id'], 'billed_amount' => '60']]])->assertOk()->assertJsonPath('data.audit_status', 'pending')->assertJsonPath('data.total_amount', '60.00')->assertJsonCount(1, 'data.lines');
    $this->postJson("/api/v1/transportation/freight-invoices/$id/audit")->assertOk()->assertJsonPath('data.audit_status', 'under_billed')->assertJsonPath('data.variance_amount', '-14.80')->assertJsonPath('data.lines.0.variance_percent', '-19.79');
    TmsTransportOrder::query()->whereKey($c['id'])->update(['expected_cost' => null]);
    $this->postJson("/api/v1/transportation/freight-invoices/$id/audit")->assertOk()->assertJsonPath('data.lines.0.expected_amount', '74.80');

    $this->getJson('/api/v1/transportation/freight-invoices?carrier_id='.$alpha['id'].'&audit_status=under_billed&search=ft-1&from=2027-06-01&to=2027-06-30')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/transportation/freight-invoices/$id")->assertOk()->assertJsonPath('data.carrier.code', 'ALPHA');
});

test('a dispute follows the audit: open, then resolved as accepted, credited or rejected', function (): void {
    ['alpha' => $alpha] = tmsTariffs();
    $a = tmsPlannedOrder($alpha['id'], ['weight_kg' => '80']);
    $b = tmsPlannedOrder($alpha['id'], ['weight_kg' => '250']);
    $invoiceFor = fn (string $number, string $orderId, string $billed) => $this->postJson('/api/v1/transportation/freight-invoices', ['carrier_id' => $alpha['id'], 'invoice_number' => $number, 'invoice_date' => '2027-06-30', 'lines' => [['transport_order_id' => $orderId, 'billed_amount' => $billed]]])->assertCreated()->json('data');

    $first = $invoiceFor('D-1', $a['id'], '85');
    $this->postJson('/api/v1/transportation/freight-invoices/'.$first['id'].'/dispute', ['reason' => 'Too much'])->assertUnprocessable()->assertJsonValidationErrors(['audit_status']);
    $this->postJson('/api/v1/transportation/freight-invoices/'.$first['id'].'/audit')->assertOk()->assertJsonPath('data.audit_status', 'over_billed')->assertJsonPath('data.variance_amount', '8.00');
    $this->postJson('/api/v1/transportation/freight-invoices/'.$first['id'].'/dispute', [])->assertUnprocessable()->assertJsonValidationErrors(['reason']);
    $this->postJson('/api/v1/transportation/freight-invoices/'.$first['id'].'/dispute', ['reason' => 'Rate agreed was 77.00'])->assertOk()->assertJsonPath('data.dispute_status', 'open')->assertJsonPath('data.dispute_reason', 'Rate agreed was 77.00');
    $this->postJson('/api/v1/transportation/freight-invoices/'.$first['id'].'/dispute', ['reason' => 'Again'])->assertUnprocessable()->assertJsonValidationErrors(['dispute_status']);
    $this->putJson('/api/v1/transportation/freight-invoices/'.$first['id'], ['notes' => 'x'])->assertUnprocessable()->assertJsonValidationErrors(['dispute_status']);
    $this->deleteJson('/api/v1/transportation/freight-invoices/'.$first['id'])->assertUnprocessable();
    $this->getJson('/api/v1/transportation/dashboard')->assertOk()->assertJsonPath('data.disputes.open', 1)->assertJsonPath('data.disputes.over_billed_amount', '8.00');

    $resolve = fn (array $payload) => $this->postJson('/api/v1/transportation/freight-invoices/'.$first['id'].'/resolve-dispute', $payload);
    $resolve(['resolution' => 'maybe', 'note' => 'x'])->assertUnprocessable()->assertJsonValidationErrors(['resolution']);
    $resolve(['resolution' => 'accepted'])->assertUnprocessable()->assertJsonValidationErrors(['note']);
    $resolve(['resolution' => 'credited', 'note' => 'Credit note'])->assertUnprocessable()->assertJsonValidationErrors(['credited_amount']);
    $resolve(['resolution' => 'credited', 'note' => 'Credit note', 'credited_amount' => '8.01'])->assertUnprocessable()->assertJsonValidationErrors(['credited_amount']);
    $resolve(['resolution' => 'rejected', 'note' => 'Dropped', 'credited_amount' => '1'])->assertUnprocessable()->assertJsonValidationErrors(['credited_amount']);
    $resolve(['resolution' => 'credited', 'note' => 'Credit note CN-7', 'credited_amount' => '8'])->assertOk()
        ->assertJsonPath('data.dispute_status', 'resolved_credited')->assertJsonPath('data.credited_amount', '8.00')->assertJsonPath('data.resolution_note', 'Credit note CN-7');
    $resolve(['resolution' => 'accepted', 'note' => 'again'])->assertUnprocessable()->assertJsonValidationErrors(['dispute_status']);
    $this->postJson('/api/v1/transportation/freight-invoices/'.$first['id'].'/audit')->assertUnprocessable()->assertJsonValidationErrors(['dispute_status']);

    $second = $invoiceFor('D-2', $b['id'], '140');
    $this->postJson('/api/v1/transportation/freight-invoices/'.$second['id'].'/audit')->assertOk()->assertJsonPath('data.audit_status', 'over_billed');
    $this->postJson('/api/v1/transportation/freight-invoices/'.$second['id'].'/dispute', ['reason' => 'No'])->assertOk();
    $this->postJson('/api/v1/transportation/freight-invoices/'.$second['id'].'/resolve-dispute', ['resolution' => 'rejected', 'note' => 'Carrier is right'])->assertOk()->assertJsonPath('data.dispute_status', 'rejected');

    $third = $invoiceFor('D-3', $a['id'], '80');
    $this->postJson('/api/v1/transportation/freight-invoices/'.$third['id'].'/audit', ['tolerance_percent' => '1'])->assertOk()->assertJsonPath('data.audit_status', 'over_billed');
    $this->getJson('/api/v1/transportation/dashboard')->assertOk()->assertJsonPath('data.disputes.open', 0)->assertJsonPath('data.disputes.over_billed_amount', '3.00')->assertJsonPath('data.disputes.credited_amount', '8.00');
    $this->getJson('/api/v1/transportation/freight-invoices?dispute_status=resolved_credited')->assertOk()->assertJsonCount(1, 'data');
    $this->deleteJson('/api/v1/transportation/freight-invoices/'.$third['id'])->assertNoContent();
});

test('the dashboard reports on-time delivery, cost per km and per kg, and the workload', function (): void {
    $carrier = tmsCarrier('alpha');
    $delivered = static function (string $deliveredAt, ?string $deliveryTo, string $weight, ?string $cost) use ($carrier): void {
        TmsTransportOrder::factory()->create([
            'tenant_id' => test()->tenant->id, 'carrier_id' => $carrier['id'], 'status' => 'delivered', 'weight_kg' => $weight, 'expected_cost' => $cost,
            'delivered_at' => $deliveredAt, 'delivery_to' => $deliveryTo, 'origin_name' => 'A', 'origin_location' => 'A', 'destination_name' => 'B', 'destination_location' => 'B',
        ]);
    };
    $delivered('2027-06-14 10:00:00', '2027-06-14 12:00:00', '100', '50.00');
    $delivered('2027-06-14 13:00:00', '2027-06-14 12:00:00', '100', '50.00');
    $delivered('2027-06-15 08:00:00', '2027-06-15 08:00:00', '200', '100.00');
    // No window: out of the on-time figure but in the cost per kg; last month: outside the period.
    $delivered('2027-06-15 07:00:00', null, '100', '50.00');
    $delivered('2027-05-01 10:00:00', '2027-05-01 12:00:00', '100', '10.00');
    TmsTransportOrder::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'planned', 'weight_kg' => '10', 'origin_name' => 'A', 'origin_location' => 'A', 'destination_name' => 'B', 'destination_location' => 'B']);
    TmsTrip::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'completed', 'actual_end_at' => '2027-06-14 18:00:00', 'actual_cost' => '300.00', 'actual_distance_km' => '200.00']);
    TmsTrip::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'completed', 'actual_end_at' => '2027-06-15 06:00:00', 'actual_cost' => '100.00', 'actual_distance_km' => '100.00']);
    // Without cost or kilometres, or old, a trip does not count.
    TmsTrip::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'completed', 'actual_end_at' => '2027-06-15 06:00:00', 'actual_cost' => null, 'actual_distance_km' => '100.00']);
    TmsTrip::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'completed', 'actual_end_at' => '2027-04-01 06:00:00', 'actual_cost' => '999.00', 'actual_distance_km' => '10.00']);
    TmsTrip::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'planned']);

    $this->getJson('/api/v1/transportation/dashboard?days=7')->assertOk()
        ->assertJsonPath('data.period.from', '2027-06-09')
        ->assertJsonPath('data.on_time.delivered', 3)->assertJsonPath('data.on_time.on_time', 2)->assertJsonPath('data.on_time.percent', '66.67')
        ->assertJsonPath('data.cost_per_km.trips', 2)->assertJsonPath('data.cost_per_km.total_cost', '400.00')->assertJsonPath('data.cost_per_km.total_km', '300.00')->assertJsonPath('data.cost_per_km.value', '1.3333')
        ->assertJsonPath('data.cost_per_kg.orders', 4)->assertJsonPath('data.cost_per_kg.value', '0.5000')
        ->assertJsonPath('data.orders_by_status.delivered', 5)->assertJsonPath('data.orders_by_status.planned', 1)->assertJsonPath('data.orders_by_status.draft', 0)
        ->assertJsonPath('data.trips_by_status.completed', 4)->assertJsonPath('data.trips_by_status.planned', 1);
    $this->getJson('/api/v1/transportation/dashboard?days=0')->assertUnprocessable();

    $empty = tmsTenant('Vuota');
    tmsAct(tmsMember($empty, 'owner'), $empty);
    $this->getJson('/api/v1/transportation/dashboard')->assertOk()->assertJsonPath('data.on_time.percent', null)->assertJsonPath('data.cost_per_km.value', null)->assertJsonPath('data.cost_per_kg.value', null);
});
