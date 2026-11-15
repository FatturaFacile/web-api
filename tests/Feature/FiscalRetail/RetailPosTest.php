<?php

declare(strict_types=1);

use App\Models\PosSale;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\FiscalRetail\Models\CommercialDocument;
use Modules\FiscalRetail\Models\DailyCorrispettivo;
use Modules\FiscalRetail\Models\RetailPromotion;
use Modules\FiscalRetail\Models\RetailReturn;
use Modules\FiscalRetail\Models\RetailSalePayment;
use Modules\FiscalRetail\Services\PromotionEngine;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // A Tuesday at 10:00.
    $this->travelTo('2027-06-15 10:00:00');
    $this->tenant = posTenant('Bottega');
    $this->cashier = posMember($this->tenant, 'owner');
    posAct($this->cashier, $this->tenant);
});

afterEach(fn () => app(TenantContext::class)->clear());

function posTenant(string $name): Tenant
{
    return Tenant::create(['name' => $name.' '.Str::random(4), 'legal_name' => $name.' Srl', 'vat_number' => '00811720580', 'fiscal_code' => '00811720580', 'country' => 'IT', 'default_currency' => 'EUR']);
}

function posMember(Tenant $tenant, string $role): User
{
    $user = User::create(['name' => ucfirst($role).' '.Str::random(4), 'email' => Str::random(10).'@pos-test.example', 'password' => bcrypt(Str::random(32)), 'locale' => 'en']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

function posAct(User $user, Tenant $tenant): void
{
    app(TenantContext::class)->setTenant($tenant);
    Sanctum::actingAs($user);
    test()->withHeaders(['X-Tenant-Id' => (string) $tenant->id]);
}

function posOpenSession(string $float = '100.00'): string
{
    $terminal = test()->postJson('/api/v1/retail/pos/terminals', ['terminal_code' => 'T-'.Str::random(5), 'name' => 'Counter'])->assertCreated()->json('data.id');

    return test()->postJson('/api/v1/retail/pos/sessions', ['pos_terminal_id' => $terminal, 'opening_float' => $float])->assertCreated()->json('data.id');
}

/**
 * @param  list<array<string, mixed>>  $items
 * @param  list<array<string, mixed>>  $payments
 * @return array<string, mixed>
 */
function posTicket(string $session, array $items, array $payments): array
{
    return test()->postJson('/api/v1/fiscal-retail/pos/tickets', ['pos_session_id' => $session, 'items' => $items, 'payments' => $payments])->assertCreated()->json('data');
}

/**
 * @param  array<string, mixed>  $overrides
 */
function posPromotion(array $overrides = []): array
{
    return test()->postJson('/api/v1/fiscal-retail/pos/promotions', [
        'code' => 'P'.Str::upper(Str::random(6)),
        'name' => 'Promotion '.Str::random(4),
        'type' => 'percentage',
        'value' => 10,
        ...$overrides,
    ])->assertCreated()->json('data');
}

function enginePromotion(array $attributes): RetailPromotion
{
    return new RetailPromotion($attributes + ['is_active' => true, 'is_stackable' => false, 'priority' => 0, 'name' => 'P', 'code' => 'P']);
}

test('a promotion needs the fields of its type', function (): void {
    $this->postJson('/api/v1/fiscal-retail/pos/promotions', ['code' => 'A', 'name' => 'A', 'type' => 'percentage'])->assertUnprocessable()->assertJsonValidationErrors('value');
    $this->postJson('/api/v1/fiscal-retail/pos/promotions', ['code' => 'A', 'name' => 'A', 'type' => 'percentage', 'value' => 150])->assertUnprocessable()->assertJsonValidationErrors('value');
    $this->postJson('/api/v1/fiscal-retail/pos/promotions', ['code' => 'A', 'name' => 'A', 'type' => 'buy_x_get_y'])->assertUnprocessable()->assertJsonValidationErrors(['product_id', 'buy_quantity', 'get_quantity']);
    $this->postJson('/api/v1/fiscal-retail/pos/promotions', ['code' => 'A', 'name' => 'A', 'type' => 'fixed', 'value' => 5, 'time_from' => '18:00'])->assertUnprocessable()->assertJsonValidationErrors('time_to');
});

test('promotion codes are unique per company and other companies cannot see them', function (): void {
    $promotion = posPromotion(['code' => 'SUMMER']);

    $this->postJson('/api/v1/fiscal-retail/pos/promotions', ['code' => 'SUMMER', 'name' => 'Again', 'type' => 'fixed', 'value' => 5])->assertUnprocessable()->assertJsonValidationErrors('code');
    $this->putJson("/api/v1/fiscal-retail/pos/promotions/{$promotion['id']}", ['name' => 'Renamed'])->assertOk()->assertJsonPath('data.name', 'Renamed');
    $this->getJson('/api/v1/fiscal-retail/pos/promotions')->assertOk()->assertJsonCount(1, 'data');

    $other = posTenant('Altra');
    posAct(posMember($other, 'owner'), $other);
    $this->getJson("/api/v1/fiscal-retail/pos/promotions/{$promotion['id']}")->assertNotFound();
    $this->getJson('/api/v1/fiscal-retail/pos/promotions')->assertOk()->assertJsonCount(0, 'data');
});

test('a promotion can be deleted', function (): void {
    $promotion = posPromotion();

    $this->deleteJson("/api/v1/fiscal-retail/pos/promotions/{$promotion['id']}")->assertNoContent();
    $this->getJson("/api/v1/fiscal-retail/pos/promotions/{$promotion['id']}")->assertNotFound();
});

test('percentage and fixed promotions discount the net amounts by rate', function (): void {
    $engine = new PromotionEngine;
    $lines = [
        ['product_id' => null, 'quantity' => '2', 'unit_price' => '10.00', 'tax_rate' => '22'],
        ['product_id' => null, 'quantity' => '1', 'unit_price' => '30.00', 'tax_rate' => '4'],
    ];

    $result = $engine->evaluate($lines, now(), collect([enginePromotion(['type' => 'percentage', 'value' => '10'])]));

    expect($result['discount_total'])->toBe('5.00')
        ->and($result['applied'][0]['by_rate'])->toBe(['22.00' => '2.00', '4.00' => '3.00']);

    $below = $engine->evaluate($lines, now(), collect([enginePromotion(['type' => 'fixed', 'value' => '7.50', 'min_subtotal' => '60'])]));
    expect($below['applied'])->toBe([]);

    $reached = $engine->evaluate($lines, now(), collect([enginePromotion(['type' => 'fixed', 'value' => '7.50', 'min_subtotal' => '50'])]));
    expect($reached['discount_total'])->toBe('7.50');
});

test('buy two get one gives away the cheapest unit of each complete group', function (): void {
    $product = (string) Str::uuid();
    $engine = new PromotionEngine;
    $promotion = enginePromotion(['type' => 'buy_x_get_y', 'product_id' => $product, 'buy_quantity' => 2, 'get_quantity' => 1]);

    $five = $engine->evaluate([['product_id' => $product, 'quantity' => '5', 'unit_price' => '4.00', 'tax_rate' => '22']], now(), collect([$promotion]));
    expect($five['discount_total'])->toBe('4.00');

    $six = $engine->evaluate([['product_id' => $product, 'quantity' => '6', 'unit_price' => '4.00', 'tax_rate' => '22']], now(), collect([$promotion]));
    expect($six['discount_total'])->toBe('8.00');

    $mixed = $engine->evaluate([
        ['product_id' => $product, 'quantity' => '1', 'unit_price' => '5.00', 'tax_rate' => '22'],
        ['product_id' => $product, 'quantity' => '2', 'unit_price' => '3.00', 'tax_rate' => '22'],
    ], now(), collect([$promotion]));
    expect($mixed['discount_total'])->toBe('3.00');

    $other = $engine->evaluate([['product_id' => (string) Str::uuid(), 'quantity' => '6', 'unit_price' => '4.00', 'tax_rate' => '22']], now(), collect([$promotion]));
    expect($other['applied'])->toBe([]);
});

test('period, weekday and time window decide when a promotion applies', function (): void {
    $engine = new PromotionEngine;
    $lines = [['product_id' => null, 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '22']];
    $happyHour = enginePromotion(['type' => 'percentage', 'value' => '20', 'weekdays' => [2, 3], 'time_from' => '09:00', 'time_to' => '11:00']);

    expect($engine->evaluate($lines, Carbon::parse('2027-06-15 10:00'), collect([$happyHour]))['discount_total'])->toBe('20.00')
        ->and($engine->evaluate($lines, Carbon::parse('2027-06-15 12:00'), collect([$happyHour]))['applied'])->toBe([])
        ->and($engine->evaluate($lines, Carbon::parse('2027-06-14 10:00'), collect([$happyHour]))['applied'])->toBe([]);

    $night = enginePromotion(['type' => 'percentage', 'value' => '10', 'time_from' => '22:00', 'time_to' => '02:00']);
    expect($engine->evaluate($lines, Carbon::parse('2027-06-15 23:30'), collect([$night]))['discount_total'])->toBe('10.00')
        ->and($engine->evaluate($lines, Carbon::parse('2027-06-15 01:00'), collect([$night]))['discount_total'])->toBe('10.00')
        ->and($engine->evaluate($lines, Carbon::parse('2027-06-15 15:00'), collect([$night]))['applied'])->toBe([]);

    $period = enginePromotion(['type' => 'fixed', 'value' => '5', 'starts_at' => Carbon::parse('2027-07-01'), 'ends_at' => Carbon::parse('2027-07-31')]);
    expect($engine->evaluate($lines, Carbon::parse('2027-06-15 10:00'), collect([$period]))['applied'])->toBe([])
        ->and($engine->evaluate($lines, Carbon::parse('2027-07-10 10:00'), collect([$period]))['discount_total'])->toBe('5.00')
        ->and($engine->evaluate($lines, Carbon::parse('2027-07-10 10:00'), collect([enginePromotion(['type' => 'fixed', 'value' => '5', 'is_active' => false])]))['applied'])->toBe([]);
});

test('a non stackable promotion takes a line alone while a stackable one works on what is left', function (): void {
    $engine = new PromotionEngine;
    $lines = [['product_id' => null, 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '22']];
    $big = enginePromotion(['type' => 'percentage', 'value' => '20', 'priority' => 10, 'code' => 'BIG', 'name' => 'Big']);
    $small = enginePromotion(['type' => 'percentage', 'value' => '10', 'priority' => 1, 'code' => 'SMALL', 'name' => 'Small']);

    $exclusive = $engine->evaluate($lines, now(), collect([$big, $small]));
    expect($exclusive['discount_total'])->toBe('20.00')->and($exclusive['applied'])->toHaveCount(1);

    $big->is_stackable = true;
    $small->is_stackable = true;
    $stacked = $engine->evaluate($lines, now(), collect([$big, $small]));
    expect($stacked['discount_total'])->toBe('28.00');
});

test('the preview prices a ticket without registering anything', function (): void {
    posPromotion(['code' => 'TEN', 'value' => 10]);

    $preview = $this->postJson('/api/v1/fiscal-retail/pos/promotions/preview', ['items' => [['description' => 'Bread', 'quantity' => 2, 'unit_price' => 10, 'tax_rate' => 22]]])
        ->assertOk()->json('data');

    expect($preview['discount_total'])->toBe('2.00')
        ->and($preview['total_net'])->toBe('18.00')
        ->and($preview['total_gross'])->toBe('21.96')
        ->and($preview['promotions'][0]['code'])->toBe('TEN')
        ->and(PosSale::count())->toBe(0);
});

test('a ticket applies the promotions and records payments split by method with the change', function (): void {
    posPromotion(['code' => 'TEN', 'value' => 10]);
    $session = posOpenSession();

    $ticket = posTicket($session, [['description' => 'Bread', 'quantity' => 2, 'unit_price' => 10, 'tax_rate' => 22]], [
        ['method' => 'card', 'amount' => 10],
        ['method' => 'cash', 'amount' => 20],
    ]);

    expect($ticket['total_net'])->toBe('18.00')
        ->and($ticket['total_gross'])->toBe('21.96')
        ->and($ticket['payment_method'])->toBe('mixed')
        ->and($ticket['promotions'][0]['discount_net'])->toBe('2.00')
        ->and(collect($ticket['items'])->pluck('unit_price')->all())->toContain('-2.0000');

    $paid = collect($ticket['payments'])->mapWithKeys(fn (array $payment): array => [$payment['method'] => $payment['amount']]);
    expect($paid['card'])->toBe('10.00')->and($paid['cash'])->toBe('11.96');

    $sale = PosSale::findOrFail($ticket['id']);
    expect((string) $sale->cash_amount)->toBe('11.96')->and((string) $sale->card_amount)->toBe('10.00');
});

test('payments that do not cover the total or exceed it without cash are refused', function (): void {
    $session = posOpenSession();
    $items = [['description' => 'Bread', 'quantity' => 1, 'unit_price' => 10, 'tax_rate' => 0]];

    $this->postJson('/api/v1/fiscal-retail/pos/tickets', ['pos_session_id' => $session, 'items' => $items, 'payments' => [['method' => 'card', 'amount' => 4]]])
        ->assertUnprocessable()->assertJsonValidationErrors('payments');
    $this->postJson('/api/v1/fiscal-retail/pos/tickets', ['pos_session_id' => $session, 'items' => $items, 'payments' => [['method' => 'card', 'amount' => 11]]])
        ->assertUnprocessable()->assertJsonValidationErrors('payments');
    $this->postJson('/api/v1/fiscal-retail/pos/tickets', ['pos_session_id' => $session, 'items' => $items, 'payments' => [['method' => 'voucher', 'amount' => 10], ['method' => 'cash', 'amount' => 5]]])
        ->assertUnprocessable()->assertJsonValidationErrors('payments');

    expect(PosSale::count())->toBe(0);
});

test('a ticket on a closed session is refused', function (): void {
    $session = posOpenSession();
    $this->postJson("/api/v1/retail/pos/sessions/{$session}/close", ['closing_cash' => 100])->assertOk();

    $this->postJson('/api/v1/fiscal-retail/pos/tickets', ['pos_session_id' => $session, 'items' => [['description' => 'Bread', 'quantity' => 1, 'unit_price' => 10, 'tax_rate' => 0]], 'payments' => [['method' => 'cash', 'amount' => 10]]])
        ->assertUnprocessable()->assertJsonValidationErrors('pos_session_id');
});

test('cash movements change the expected cash and the variance at closing', function (): void {
    $session = posOpenSession('100.00');
    posTicket($session, [['description' => 'Bread', 'quantity' => 1, 'unit_price' => 20, 'tax_rate' => 0]], [['method' => 'cash', 'amount' => 20]]);
    posTicket($session, [['description' => 'Wine', 'quantity' => 1, 'unit_price' => 30, 'tax_rate' => 0]], [['method' => 'card', 'amount' => 30]]);

    $this->postJson("/api/v1/fiscal-retail/pos/sessions/{$session}/cash-movements", ['type' => 'pay_in', 'amount' => 50])->assertCreated();
    $this->postJson("/api/v1/fiscal-retail/pos/sessions/{$session}/cash-movements", ['type' => 'pay_out', 'amount' => 30, 'reason' => 'Supplier'])->assertCreated();
    $this->postJson("/api/v1/fiscal-retail/pos/sessions/{$session}/cash-movements", ['type' => 'safe_drop', 'amount' => 40])->assertCreated();

    $listing = $this->getJson("/api/v1/fiscal-retail/pos/sessions/{$session}/cash-movements")->assertOk();
    $listing->assertJsonCount(3, 'data');
    expect($listing->json('summary.expected_cash'))->toBe('100.00');

    $closed = $this->postJson("/api/v1/retail/pos/sessions/{$session}/close", ['closing_cash' => 97.5])->assertOk()->json('data');
    expect((string) $closed['expected_cash'])->toBe('100.00')->and((string) $closed['cash_difference'])->toBe('-2.50');
});

test('a pay out needs a reason and cannot exceed the cash in the drawer', function (): void {
    $session = posOpenSession('10.00');

    $this->postJson("/api/v1/fiscal-retail/pos/sessions/{$session}/cash-movements", ['type' => 'pay_out', 'amount' => 5])->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->postJson("/api/v1/fiscal-retail/pos/sessions/{$session}/cash-movements", ['type' => 'pay_out', 'amount' => 50, 'reason' => 'Too much'])->assertUnprocessable()->assertJsonValidationErrors('amount');

    $this->postJson("/api/v1/retail/pos/sessions/{$session}/close", ['closing_cash' => 10])->assertOk();
    $this->postJson("/api/v1/fiscal-retail/pos/sessions/{$session}/cash-movements", ['type' => 'pay_in', 'amount' => 5])->assertUnprocessable()->assertJsonValidationErrors('pos_session_id');
});

test('goods are returned in part, refunded in cash and the quantity cannot be returned twice', function (): void {
    $session = posOpenSession('100.00');
    $ticket = posTicket($session, [['description' => 'Shirt', 'quantity' => 3, 'unit_price' => 10, 'tax_rate' => 22]], [['method' => 'cash', 'amount' => 40]]);
    $item = $ticket['items'][0]['id'];

    $return = $this->postJson("/api/v1/fiscal-retail/pos/sales/{$ticket['id']}/returns", [
        'pos_session_id' => $session, 'lines' => [['pos_sale_item_id' => $item, 'quantity' => 1]], 'refund_method' => 'cash', 'reason' => 'Wrong size',
    ])->assertCreated()->json('data');

    expect($return['number'])->toBe('RET-2027-0001')
        ->and($return['total_net'])->toBe('10.00')
        ->and($return['total_gross'])->toBe('12.20')
        ->and($return['cash_refunded'])->toBe('12.20');

    $this->getJson("/api/v1/fiscal-retail/pos/tickets/{$ticket['id']}")->assertJsonPath('data.items.0.returnable_quantity', '2.0000');

    $this->postJson("/api/v1/fiscal-retail/pos/sales/{$ticket['id']}/returns", [
        'pos_session_id' => $session, 'lines' => [['pos_sale_item_id' => $item, 'quantity' => 3]], 'refund_method' => 'cash',
    ])->assertUnprocessable()->assertJsonValidationErrors('lines.0.quantity');

    $summary = $this->getJson("/api/v1/fiscal-retail/pos/sessions/{$session}/cash-movements")->json('summary');
    expect($summary['cash_refunds'])->toBe('12.20')->and($summary['expected_cash'])->toBe('124.40');

    $closed = $this->postJson("/api/v1/retail/pos/sessions/{$session}/close", ['closing_cash' => 124.4])->assertOk()->json('data');
    expect((string) $closed['cash_difference'])->toBe('0.00');
});

test('a return gives back the share of the promotion discount and corrects the commercial document', function (): void {
    posPromotion(['code' => 'TEN', 'value' => 10]);
    $session = posOpenSession('0.00');
    $ticket = posTicket($session, [['description' => 'Shirt', 'quantity' => 2, 'unit_price' => 50, 'tax_rate' => 22]], [['method' => 'card', 'amount' => 109.8]]);

    $this->postJson('/api/v1/fiscal-retail/commercial-documents', ['pos_sale_id' => $ticket['id']])->assertCreated();
    $item = collect($ticket['items'])->firstWhere('unit_price', '50.0000')['id'];

    $return = $this->postJson("/api/v1/fiscal-retail/pos/sales/{$ticket['id']}/returns", [
        'pos_session_id' => $session, 'lines' => [['pos_sale_item_id' => $item, 'quantity' => 1]], 'refund_method' => 'card',
    ])->assertCreated()->json('data');

    expect($return['total_net'])->toBe('45.00')
        ->and($return['total_gross'])->toBe('54.90')
        ->and($return['other_refunded'])->toBe('54.90')
        ->and($return['commercial_document_id'])->not->toBeNull();

    $document = CommercialDocument::findOrFail($return['commercial_document_id']);
    expect($document->document_type)->toBe('return')->and((string) $document->total_gross)->toBe('54.90');
});

test('an exchange pays the new ticket with the value of the returned goods', function (): void {
    $session = posOpenSession('100.00');
    $ticket = posTicket($session, [['description' => 'Shirt', 'quantity' => 1, 'unit_price' => 50, 'tax_rate' => 0]], [['method' => 'cash', 'amount' => 50]]);
    $item = $ticket['items'][0]['id'];

    $return = $this->postJson("/api/v1/fiscal-retail/pos/sales/{$ticket['id']}/returns", [
        'pos_session_id' => $session,
        'lines' => [['pos_sale_item_id' => $item, 'quantity' => 1]],
        'refund_method' => 'cash',
        'exchange' => [
            'items' => [['description' => 'Jacket', 'quantity' => 1, 'unit_price' => 80, 'tax_rate' => 0]],
            'payments' => [['method' => 'card', 'amount' => 30]],
        ],
    ])->assertCreated()->json('data');

    expect($return['credit_applied'])->toBe('50.00')->and($return['cash_refunded'])->toBe('0.00')->and($return['exchange_sale_id'])->not->toBeNull();

    $exchange = $this->getJson("/api/v1/fiscal-retail/pos/tickets/{$return['exchange_sale_id']}")->assertOk()->json('data');
    $methods = collect($exchange['payments'])->mapWithKeys(fn (array $payment): array => [$payment['method'] => $payment['amount']]);
    expect($exchange['total_gross'])->toBe('80.00')
        ->and($methods[RetailSalePayment::ReturnCredit])->toBe('50.00')
        ->and($methods['card'])->toBe('30.00');
});

test('an exchange whose difference is not paid is refused and nothing is recorded', function (): void {
    $session = posOpenSession('100.00');
    $ticket = posTicket($session, [['description' => 'Shirt', 'quantity' => 1, 'unit_price' => 50, 'tax_rate' => 0]], [['method' => 'cash', 'amount' => 50]]);

    $this->postJson("/api/v1/fiscal-retail/pos/sales/{$ticket['id']}/returns", [
        'pos_session_id' => $session,
        'lines' => [['pos_sale_item_id' => $ticket['items'][0]['id'], 'quantity' => 1]],
        'refund_method' => 'cash',
        'exchange' => ['items' => [['description' => 'Jacket', 'quantity' => 1, 'unit_price' => 80, 'tax_rate' => 0]]],
    ])->assertUnprocessable()->assertJsonValidationErrors('payments');

    expect(RetailReturn::count())->toBe(0)->and(PosSale::count())->toBe(1);
});

test('an exchange of cheaper goods refunds the difference', function (): void {
    $session = posOpenSession('100.00');
    $ticket = posTicket($session, [['description' => 'Jacket', 'quantity' => 1, 'unit_price' => 80, 'tax_rate' => 0]], [['method' => 'card', 'amount' => 80]]);

    $return = $this->postJson("/api/v1/fiscal-retail/pos/sales/{$ticket['id']}/returns", [
        'pos_session_id' => $session,
        'lines' => [['pos_sale_item_id' => $ticket['items'][0]['id'], 'quantity' => 1]],
        'refund_method' => 'cash',
        'exchange' => ['items' => [['description' => 'Shirt', 'quantity' => 1, 'unit_price' => 50, 'tax_rate' => 0]]],
    ])->assertCreated()->json('data');

    expect($return['credit_applied'])->toBe('50.00')->and($return['cash_refunded'])->toBe('30.00');
});

test('the X report reads an open session and the Z report is issued at closing', function (): void {
    posPromotion(['code' => 'TEN', 'value' => 10]);
    $session = posOpenSession('100.00');
    $ticket = posTicket($session, [['description' => 'Bread', 'quantity' => 2, 'unit_price' => 10, 'tax_rate' => 22]], [['method' => 'cash', 'amount' => 30]]);
    posTicket($session, [['description' => 'Book', 'quantity' => 1, 'unit_price' => 20, 'tax_rate' => 4]], [['method' => 'card', 'amount' => 10], ['method' => 'voucher', 'amount' => 8.72]]);
    $this->postJson("/api/v1/fiscal-retail/pos/sales/{$ticket['id']}/returns", [
        'pos_session_id' => $session, 'lines' => [['pos_sale_item_id' => collect($ticket['items'])->firstWhere('unit_price', '10.0000')['id'], 'quantity' => 1]], 'refund_method' => 'cash',
    ])->assertCreated();

    $x = $this->getJson("/api/v1/fiscal-retail/pos/sessions/{$session}/x-report")->assertOk()->json('data');
    expect($x['kind'])->toBe('x')
        ->and($x['sales_count'])->toBe(2)
        ->and($x['returns_count'])->toBe(1)
        ->and($x['payments_by_method'])->toBe(['card' => '10.00', 'cash' => '21.96', 'voucher' => '8.72'])
        ->and($x['cash']['expected_cash'])->toBe('110.98')
        ->and(collect($x['vat_breakdown'])->pluck('tax_rate')->all())->toBe(['4.00', '22.00']);

    $this->getJson("/api/v1/fiscal-retail/pos/sessions/{$session}/z-report")->assertUnprocessable()->assertJsonValidationErrors('pos_session_id');

    $this->postJson("/api/v1/retail/pos/sessions/{$session}/close", ['closing_cash' => 110])->assertOk();

    $z = $this->getJson("/api/v1/fiscal-retail/pos/sessions/{$session}/z-report")->assertOk()->json('data');
    expect($z['kind'])->toBe('z')
        ->and($z['number'])->toBe('Z-2027-0001')
        ->and($z['business_date'])->toBe('2027-06-15')
        ->and($z['totals']['cash']['variance'])->toBe('-0.98')
        ->and($z['totals']['cash']['counted_cash'])->toBe('110.00')
        ->and($z['totals']['reconciliation']['documents_count'])->toBe(0);

    $this->getJson("/api/v1/fiscal-retail/pos/sessions/{$session}/x-report")->assertUnprocessable();
    $this->getJson('/api/v1/fiscal-retail/pos/z-reports')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/fiscal-retail/pos/sessions/{$session}/z-report")->assertJsonPath('data.id', $z['id']);
});

test('the Z report reconciles the takings with the commercial documents and the daily corrispettivi', function (): void {
    $session = posOpenSession('0.00');
    $first = posTicket($session, [['description' => 'Bread', 'quantity' => 1, 'unit_price' => 10, 'tax_rate' => 22]], [['method' => 'card', 'amount' => 12.2]]);
    posTicket($session, [['description' => 'Wine', 'quantity' => 1, 'unit_price' => 20, 'tax_rate' => 22]], [['method' => 'card', 'amount' => 24.4]]);
    $this->postJson('/api/v1/fiscal-retail/commercial-documents', ['pos_sale_id' => $first['id']])->assertCreated();
    DailyCorrispettivo::factory()->create(['tenant_id' => $this->tenant->id, 'business_date' => '2027-06-15', 'total_gross' => '12.20', 'closed_at' => now()]);

    $this->postJson("/api/v1/retail/pos/sessions/{$session}/close", ['closing_cash' => 0])->assertOk();

    $reconciliation = $this->getJson("/api/v1/fiscal-retail/pos/sessions/{$session}/z-report")->assertOk()->json('data.totals.reconciliation');
    expect($reconciliation['documented_gross'])->toBe('12.20')
        ->and($reconciliation['undocumented_gross'])->toBe('24.40')
        ->and($reconciliation['documents_count'])->toBe(1)
        ->and($reconciliation['corrispettivi_day_closed'])->toBeTrue()
        ->and($reconciliation['corrispettivi_total_gross'])->toBe('12.20');
});

test('Z reports are numbered progressively per company', function (): void {
    foreach ([1, 2] as $_) {
        $session = posOpenSession('0.00');
        $this->postJson("/api/v1/retail/pos/sessions/{$session}/close", ['closing_cash' => 0])->assertOk();
    }

    $numbers = collect($this->getJson('/api/v1/fiscal-retail/pos/z-reports')->assertOk()->json('data'))->pluck('number')->sort()->values()->all();
    expect($numbers)->toBe(['Z-2027-0001', 'Z-2027-0002']);
});

test('the returns of another company are not visible', function (): void {
    $session = posOpenSession();
    $ticket = posTicket($session, [['description' => 'Shirt', 'quantity' => 1, 'unit_price' => 10, 'tax_rate' => 0]], [['method' => 'cash', 'amount' => 10]]);

    $other = posTenant('Altra');
    posAct(posMember($other, 'owner'), $other);

    $this->getJson("/api/v1/fiscal-retail/pos/tickets/{$ticket['id']}")->assertNotFound();
    $this->getJson("/api/v1/fiscal-retail/pos/sessions/{$session}/cash-movements")->assertNotFound();
    $this->getJson('/api/v1/fiscal-retail/pos/returns')->assertOk()->assertJsonCount(0, 'data');
});

test('the whole sale refund gives back what earlier returns left and cannot be repeated', function (): void {
    $session = posOpenSession('100.00');
    $ticket = posTicket($session, [['description' => 'Shirt', 'quantity' => 3, 'unit_price' => 10, 'tax_rate' => 22]], [['method' => 'cash', 'amount' => 40]]);
    $item = $ticket['items'][0]['id'];

    $this->postJson("/api/v1/fiscal-retail/pos/sales/{$ticket['id']}/returns", [
        'pos_session_id' => $session, 'lines' => [['pos_sale_item_id' => $item, 'quantity' => 1]], 'refund_method' => 'cash',
    ])->assertCreated();

    $refund = $this->postJson("/api/v1/fiscal-retail/pos/sales/{$ticket['id']}/refund", ['pos_session_id' => $session, 'refund_method' => 'cash', 'reason' => 'Customer left'])
        ->assertCreated()->json('data');

    expect($refund['total_gross'])->toBe('24.40')->and($refund['cash_refunded'])->toBe('24.40');

    $this->getJson("/api/v1/fiscal-retail/pos/tickets/{$ticket['id']}")->assertJsonPath('data.items.0.returnable_quantity', '0.0000');
    $this->postJson("/api/v1/fiscal-retail/pos/sales/{$ticket['id']}/refund", ['pos_session_id' => $session, 'refund_method' => 'cash'])
        ->assertUnprocessable()->assertJsonValidationErrors('pos_sale_id');

    $summary = $this->getJson("/api/v1/fiscal-retail/pos/sessions/{$session}/cash-movements")->json('summary');
    expect($summary['cash_refunds'])->toBe('36.60');
});

test('the legacy whole sale refund is refused when the sale already has returns', function (): void {
    $session = posOpenSession('100.00');
    $ticket = posTicket($session, [['description' => 'Shirt', 'quantity' => 2, 'unit_price' => 10, 'tax_rate' => 22]], [['method' => 'cash', 'amount' => 30]]);

    $this->postJson("/api/v1/fiscal-retail/pos/sales/{$ticket['id']}/returns", [
        'pos_session_id' => $session, 'lines' => [['pos_sale_item_id' => $ticket['items'][0]['id'], 'quantity' => 1]], 'refund_method' => 'cash',
    ])->assertCreated();

    $this->postJson("/api/v1/retail/pos/sales/{$ticket['id']}/refund")->assertUnprocessable()->assertJsonValidationErrors('sale');
});

test('a corrispettivi day cannot be closed while a cash session is open', function (): void {
    $session = posOpenSession('0.00');
    $ticket = posTicket($session, [['description' => 'Bread', 'quantity' => 1, 'unit_price' => 10, 'tax_rate' => 22]], [['method' => 'card', 'amount' => 12.2]]);
    $this->postJson('/api/v1/fiscal-retail/commercial-documents', ['pos_sale_id' => $ticket['id']])->assertCreated();

    $this->postJson('/api/v1/fiscal-retail/corrispettivi/daily/2027-06-15/close')->assertUnprocessable()->assertJsonValidationErrors('date');

    $this->postJson("/api/v1/retail/pos/sessions/{$session}/close", ['closing_cash' => 0])->assertOk();
    $this->postJson('/api/v1/fiscal-retail/corrispettivi/daily/2027-06-15/close')->assertCreated();
});
