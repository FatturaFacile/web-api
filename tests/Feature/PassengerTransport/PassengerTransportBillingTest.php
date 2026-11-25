<?php

declare(strict_types=1);

use App\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Invoicing\Models\SalesInvoiceItem;
use Modules\PassengerTransport\Models\PassengerBooking;

require_once __DIR__.'/PassengerTransportSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    ptrSetUp($this);
});

/**
 * A completed booking of the customer with a fixed kilometre split (40 km in Italy, 20 km in Switzerland).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function ptrCompletedBooking(object $test, array $overrides = []): array
{
    $booking = ptrBooking($test, array_merge([
        'pickup_lat' => null, 'pickup_lng' => null, 'dropoff_lat' => null, 'dropoff_lng' => null,
        'total_distance_km' => 60, 'it_territory_km' => 40, 'ch_territory_km' => 20, 'agreed_price_net' => '200.00', 'currency' => 'EUR',
        'pickup_datetime' => now()->startOfMonth()->addDays(2)->setTime(10, 0)->toDateTimeString(),
    ], $overrides));

    PassengerBooking::query()->whereKey($booking['id'])->update(['status' => 'completed']);

    return $booking;
}

it('invoices a completed booking with one line per territory and the natura code of the foreign leg', function (): void {
    $booking = ptrCompletedBooking($this);

    $response = $this->postJson('/api/v1/passenger-transport/bookings/'.$booking['id'].'/invoice')->assertCreated();
    $items = SalesInvoiceItem::query()->where('invoice_id', $response->json('data.invoice_id'))->orderByDesc('tax_rate')->get();

    expect($items)->toHaveCount(2)
        ->and((float) $items[0]->tax_rate)->toBe(10.0)
        ->and((string) $items[0]->line_total)->toBe('133.33')
        ->and($items[0]->natura)->toBeNull()
        ->and((float) $items[1]->tax_rate)->toBe(0.0)
        ->and((string) $items[1]->line_total)->toBe('66.67')
        ->and($items[1]->natura)->toBe('N2.1')
        ->and((float) $response->json('data.total_gross'))->toBe(213.33)
        ->and($response->json('data.booking.status'))->toBe('invoiced')
        ->and($response->json('data.booking.invoice_id'))->toBe($response->json('data.invoice_id'));
});

it('does not invoice a booking twice or before it is completed', function (): void {
    $draft = ptrBooking($this);
    $done = ptrCompletedBooking($this);

    $this->postJson('/api/v1/passenger-transport/bookings/'.$draft['id'].'/invoice')->assertStatus(409)->assertJsonPath('code', 'invoice_booking_not_completed');
    $this->postJson('/api/v1/passenger-transport/bookings/'.$done['id'].'/invoice')->assertCreated();
    $this->postJson('/api/v1/passenger-transport/bookings/'.$done['id'].'/invoice')->assertStatus(409);
});

it('previews and issues the month-end invoice of a customer, adding the monthly flat amount of a school bus contract', function (): void {
    $first = ptrCompletedBooking($this);
    $second = ptrCompletedBooking($this, ['agreed_price_net' => '100.00']);
    $this->postJson('/api/v1/passenger-transport/school-contracts', [
        'contact_id' => $this->customer->id, 'code' => 'SCH-1', 'name' => 'Scuolabus', 'currency' => 'EUR', 'monthly_flat_amount' => '1000.00',
        'rate_card' => [['route_code' => 'N', 'label' => 'Nord', 'price' => '150.00']],
    ])->assertCreated();
    $period = now()->format('Y-m');

    $preview = $this->getJson('/api/v1/passenger-transport/billing/monthly-preview?period='.$period)->assertOk();
    expect($preview->json('data'))->toHaveCount(1)
        ->and($preview->json('data.0.bookings_count'))->toBe(2)
        ->and($preview->json('data.0.monthly_flat_amount'))->toBe('1000.00')
        ->and($preview->json('data.0.total_net'))->toBe('1300.00')
        ->and($preview->json('data.0.total_tax'))->toBe('120.00');

    $issued = $this->postJson('/api/v1/passenger-transport/billing/monthly-invoices', ['period' => $period])->assertCreated();
    $invoiceId = $issued->json('data.0.invoice_id');

    expect($issued->json('data'))->toHaveCount(1)
        ->and(SalesInvoiceItem::query()->where('invoice_id', $invoiceId)->count())->toBe(5)
        ->and(PassengerBooking::query()->whereIn('id', [$first['id'], $second['id']])->where('status', 'invoiced')->where('invoice_id', $invoiceId)->count())->toBe(2);

    $this->getJson('/api/v1/passenger-transport/billing/monthly-preview?period='.$period)->assertOk()->assertJsonCount(0, 'data');
});

it('bills a customer that only has a monthly flat amount and no trips', function (): void {
    $this->postJson('/api/v1/passenger-transport/school-contracts', ['contact_id' => $this->customer->id, 'code' => 'SCH-2', 'name' => 'Scuolabus', 'currency' => 'EUR', 'monthly_flat_amount' => '500.00'])->assertCreated();

    $preview = $this->getJson('/api/v1/passenger-transport/billing/monthly-preview?period='.now()->format('Y-m'))->assertOk();

    expect($preview->json('data.0.bookings_count'))->toBe(0)
        ->and($preview->json('data.0.total_net'))->toBe('500.00');
});

it('keeps the bookings of each customer on their own invoice and leaves other months alone', function (): void {
    $other = Contact::query()->create(['company_name' => 'Agenzia Eventi', 'type' => 'customer', 'country' => 'CH']);
    ptrCompletedBooking($this);
    ptrCompletedBooking($this, ['ordering_customer_id' => $other->id]);
    ptrCompletedBooking($this, ['pickup_datetime' => now()->subMonths(2)->setTime(10, 0)->toDateTimeString()]);

    $preview = $this->getJson('/api/v1/passenger-transport/billing/monthly-preview?period='.now()->format('Y-m'))->assertOk();
    expect($preview->json('data'))->toHaveCount(2);

    $only = $this->postJson('/api/v1/passenger-transport/billing/monthly-invoices', ['period' => now()->format('Y-m'), 'contact_ids' => [$other->id]])->assertCreated();
    expect($only->json('data'))->toHaveCount(1)
        ->and($only->json('data.0.customer_id'))->toBe($other->id);
});

it('refuses an invalid billing period', function (): void {
    $this->getJson('/api/v1/passenger-transport/billing/monthly-preview?period=2028-13')->assertUnprocessable();
});

it('keeps the month-end invoicing to finance', function (): void {
    treasuryActor($this, 'dispatcher');

    $this->getJson('/api/v1/passenger-transport/billing/monthly-preview?period='.now()->format('Y-m'))->assertOk();
    $this->postJson('/api/v1/passenger-transport/billing/monthly-invoices', ['period' => now()->format('Y-m')])->assertForbidden();
});

it('books a concierge transfer at the tariff of the contract with the commission of the hotel', function (): void {
    $contract = $this->postJson('/api/v1/passenger-transport/hotel-contracts', [
        'contact_id' => $this->customer->id, 'code' => 'HTL-1', 'name' => 'Hotel Test', 'commission_rate' => '10', 'currency' => 'CHF',
        'valid_from' => now()->startOfYear()->toDateString(), 'valid_to' => now()->endOfYear()->toDateString(),
        'rate_card' => [[
            'route_code' => 'MXP-LUG', 'origin_label' => 'Milano Malpensa (MXP)', 'destination_label' => 'Lugano', 'vehicle_category' => 'sedan_ncc', 'price' => '220.00',
            'origin_country' => 'IT', 'destination_country' => 'CH', 'origin_lat' => 45.6306, 'origin_lng' => 8.7281, 'destination_lat' => 46.0037, 'destination_lng' => 8.9511, 'border_crossing' => 'chiasso_brogeda',
        ]],
    ])->assertCreated()->json('data');

    $response = $this->postJson('/api/v1/passenger-transport/hotel-contracts/'.$contract['id'].'/bookings', [
        'route_code' => 'MXP-LUG', 'pickup_datetime' => now()->addDays(3)->setTime(11, 0)->toDateTimeString(), 'lead_passenger_name' => 'Mrs. Keller', 'passengers_count' => 2,
    ])->assertCreated();

    expect($response->json('data.status'))->toBe('confirmed')
        ->and($response->json('data.agreed_price_net'))->toBe('220.00')
        ->and($response->json('data.hotel_concierge_commission_amount'))->toBe('22.00')
        ->and($response->json('data.ordering_customer_id'))->toBe($this->customer->id)
        ->and($response->json('data.hotel_contract_id'))->toBe($contract['id'])
        ->and($response->json('data.vip_welcome_sign_text'))->toBe('Mrs. Keller')
        ->and($response->json('data.border_crossing_point'))->toBe('chiasso_brogeda')
        ->and((float) $response->json('data.ch_territory_km'))->toBeGreaterThan(0)
        ->and($response->json('data.b2b_concierge_user_id'))->toBe($this->actor->id);
});

it('refuses a concierge booking for a route outside the rate card or a date outside the contract', function (): void {
    $contract = $this->postJson('/api/v1/passenger-transport/hotel-contracts', [
        'contact_id' => $this->customer->id, 'code' => 'HTL-2', 'name' => 'Hotel Test', 'commission_rate' => '10', 'currency' => 'CHF',
        'valid_from' => now()->toDateString(), 'valid_to' => now()->addMonth()->toDateString(),
        'rate_card' => [['route_code' => 'A-B', 'origin_label' => 'A', 'destination_label' => 'B', 'vehicle_category' => 'sedan_ncc', 'price' => '50.00']],
    ])->assertCreated()->json('data');
    $url = '/api/v1/passenger-transport/hotel-contracts/'.$contract['id'].'/bookings';

    $this->postJson($url, ['route_code' => 'X-Y', 'pickup_datetime' => now()->addDays(3)->toDateTimeString(), 'lead_passenger_name' => 'Guest'])->assertUnprocessable()->assertJsonPath('code', 'contract_route_unknown');
    $this->postJson($url, ['route_code' => 'A-B', 'pickup_datetime' => now()->addMonths(3)->toDateTimeString(), 'lead_passenger_name' => 'Guest'])->assertUnprocessable()->assertJsonPath('code', 'contract_not_valid_on_date');
});

it('keeps the codes of the contracts unique per company and protects contracts that have bookings', function (): void {
    $payload = ['contact_id' => $this->customer->id, 'code' => 'HTL-3', 'name' => 'Hotel Test', 'commission_rate' => '5', 'rate_card' => [['route_code' => 'A-B', 'origin_label' => 'A', 'destination_label' => 'B', 'vehicle_category' => 'sedan_ncc', 'price' => '50.00']]];
    $contract = $this->postJson('/api/v1/passenger-transport/hotel-contracts', $payload)->assertCreated()->json('data');

    $this->postJson('/api/v1/passenger-transport/hotel-contracts', $payload)->assertUnprocessable()->assertJsonValidationErrors('code');
    $this->putJson('/api/v1/passenger-transport/hotel-contracts/'.$contract['id'], ['name' => 'Renamed'])->assertOk()->assertJsonPath('data.name', 'Renamed');

    $this->postJson('/api/v1/passenger-transport/hotel-contracts/'.$contract['id'].'/bookings', ['route_code' => 'A-B', 'pickup_datetime' => now()->addDays(2)->toDateTimeString(), 'lead_passenger_name' => 'Guest'])->assertCreated();
    $this->deleteJson('/api/v1/passenger-transport/hotel-contracts/'.$contract['id'])->assertStatus(409)->assertJsonPath('code', 'contract_has_bookings');
});

it('cedes a trip to a partner carrier and keeps the difference as commission', function (): void {
    $carrier = Contact::query()->create(['company_name' => 'NCC Collega Como', 'type' => 'supplier', 'country' => 'IT']);
    $booking = ptrBooking($this);
    $url = '/api/v1/passenger-transport/bookings/'.$booking['id'].'/subcontract';

    $this->postJson($url, ['action' => 'cede', 'carrier_id' => $carrier->id, 'amount' => '300.00'])->assertUnprocessable()->assertJsonPath('code', 'subcontract_payout_invalid');

    $ceded = $this->postJson($url, ['action' => 'cede', 'carrier_id' => $carrier->id, 'amount' => '150.00'])->assertOk();

    expect($ceded->json('data.exchange_direction'))->toBe('ceded')
        ->and($ceded->json('data.subcontractor_payout_amount'))->toBe('150.00')
        ->and($ceded->json('data.assignor_commission_amount'))->toBe('70.00')
        ->and($ceded->json('data.subcontracted_carrier_name'))->toBe('NCC Collega Como');
});

it('acquires a trip from a partner carrier and shows the net balance of the exchange in the ledger', function (): void {
    $carrier = Contact::query()->create(['company_name' => 'NCC Collega Como', 'type' => 'supplier', 'country' => 'IT']);
    $ceded = ptrBooking($this);
    $acquired = ptrBooking($this, ['agreed_price_net' => '400.00']);

    $this->postJson('/api/v1/passenger-transport/bookings/'.$ceded['id'].'/subcontract', ['action' => 'cede', 'carrier_id' => $carrier->id, 'amount' => '150.00'])->assertOk();
    $response = $this->postJson('/api/v1/passenger-transport/bookings/'.$acquired['id'].'/subcontract', ['action' => 'acquire', 'carrier_id' => $carrier->id, 'amount' => '60.00'])->assertOk();

    expect($response->json('data.exchange_direction'))->toBe('acquired')
        ->and($response->json('data.assignor_commission_amount'))->toBe('60.00')
        ->and($response->json('data.subcontractor_payout_amount'))->toBe('340.00');

    $ledger = $this->getJson('/api/v1/passenger-transport/subcontract/ledger')->assertOk();
    $partner = $ledger->json('data.partners.0');

    expect($ledger->json('data.rows'))->toHaveCount(2)
        ->and($partner['ceded_count'])->toBe(1)
        ->and($partner['ceded_payout'])->toBe('150.00')
        ->and($partner['ceded_commission_kept'])->toBe('70.00')
        ->and($partner['acquired_count'])->toBe(1)
        ->and($partner['acquired_payout'])->toBe('340.00')
        ->and($partner['acquired_commission_owed'])->toBe('60.00')
        ->and($partner['net_balance'])->toBe('190.00');
});

it('does not invoice an acquired trip to the customer and releases an exchange', function (): void {
    $carrier = Contact::query()->create(['company_name' => 'NCC Collega Como', 'type' => 'supplier', 'country' => 'IT']);
    $booking = ptrCompletedBooking($this);

    PassengerBooking::query()->whereKey($booking['id'])->update(['status' => 'draft']);
    $this->postJson('/api/v1/passenger-transport/bookings/'.$booking['id'].'/subcontract', ['action' => 'acquire', 'carrier_id' => $carrier->id, 'amount' => '20.00'])->assertOk();
    PassengerBooking::query()->whereKey($booking['id'])->update(['status' => 'completed']);

    expect($this->getJson('/api/v1/passenger-transport/billing/monthly-preview?period='.now()->format('Y-m'))->json('data'))->toBe([]);

    PassengerBooking::query()->whereKey($booking['id'])->update(['status' => 'draft']);
    $this->postJson('/api/v1/passenger-transport/bookings/'.$booking['id'].'/subcontract', ['action' => 'release'])->assertOk()->assertJsonPath('data.exchange_direction', 'none');
});
