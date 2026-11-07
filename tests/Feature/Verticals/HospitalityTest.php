<?php

declare(strict_types=1);

use App\Models\PosSale;
use App\Models\PosSession;
use App\Models\PosTerminal;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = verticalTenant();
    $this->manager = verticalMember($this->tenant, 'admin');
    $this->waiter = verticalMember($this->tenant, 'member');

    app(TenantContext::class)->setTenant($this->tenant);
    $terminal = PosTerminal::create(['terminal_code' => 'T1', 'name' => 'Cassa sala', 'is_active' => true]);
    $this->session = PosSession::create(['pos_terminal_id' => $terminal->id, 'user_id' => $this->waiter->id, 'opened_at' => now(), 'opening_float' => 0, 'status' => 'open']);

    verticalApi($this->manager, $this->tenant);
    $this->room = $this->postJson('/api/v1/hospitality/rooms', ['name' => 'Sala', 'width' => 400, 'height' => 300])->assertCreated()->json('data.id');
    $this->table = $this->postJson("/api/v1/hospitality/rooms/{$this->room}/tables", ['name' => 'T1', 'seats' => 4, 'pos_x' => 10, 'pos_y' => 20])->assertCreated()->json('data.id');
    $this->otherTable = $this->postJson("/api/v1/hospitality/rooms/{$this->room}/tables", ['name' => 'T2', 'seats' => 2])->assertCreated()->json('data.id');

    $this->seat = function (): array {
        $order = $this->postJson('/api/v1/hospitality/orders', ['table_id' => $this->table, 'covers' => 2])->assertCreated()->json('data.id');
        $items = $this->postJson("/api/v1/hospitality/orders/{$order}/items", ['items' => [
            ['description' => 'Antipasto misto', 'quantity' => '1', 'unit_price' => '18', 'tax_rate' => '8.1', 'course' => 1, 'station' => 'kitchen'],
            ['description' => 'Risotto', 'quantity' => '2', 'unit_price' => '24', 'tax_rate' => '8.1', 'course' => 2, 'station' => 'kitchen', 'notes' => 'Senza formaggio'],
            ['description' => 'Acqua', 'quantity' => '2', 'unit_price' => '5', 'tax_rate' => '8.1', 'course' => 1, 'station' => 'bar'],
        ]])->assertCreated()->json('data');

        return ['order' => $order, 'items' => collect($items)->pluck('id', 'description')->all()];
    };
});

test('orders run by course through the stations and the plan shows the occupied tables', function (): void {
    verticalApi($this->waiter, $this->tenant);
    $seated = ($this->seat)();
    $this->postJson('/api/v1/hospitality/orders', ['table_id' => $this->table, 'covers' => 2])->assertUnprocessable();

    $plan = collect($this->getJson("/api/v1/hospitality/rooms/{$this->room}/plan")->assertOk()->json('data.tables'))->keyBy('name');
    expect($plan['T1'])->toMatchArray(['status' => 'occupied', 'covers' => 2, 'pos_x' => 10])->and($plan['T2']['status'])->toBe('free');

    $tickets = $this->postJson("/api/v1/hospitality/orders/{$seated['order']}/fire", ['course' => 1])->assertCreated()->assertJsonCount(2, 'data');
    $this->postJson("/api/v1/hospitality/orders/{$seated['order']}/fire", ['course' => 1])->assertUnprocessable();
    $this->getJson('/api/v1/hospitality/kitchen-tickets?station=kitchen')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.lines.0.description', 'Antipasto misto');

    $kitchenTicket = collect($tickets->json('data'))->firstWhere('station', 'kitchen')['id'];
    $this->postJson("/api/v1/hospitality/kitchen-tickets/{$kitchenTicket}/status", ['status' => 'served'])->assertUnprocessable();
    $this->postJson("/api/v1/hospitality/kitchen-tickets/{$kitchenTicket}/status", ['status' => 'ready'])->assertOk();
    $this->postJson("/api/v1/hospitality/orders/{$seated['order']}/fire", ['course' => 2])->assertCreated()->assertJsonPath('data.0.lines.0.notes', 'Senza formaggio');

    $order = $this->getJson("/api/v1/hospitality/orders/{$seated['order']}")->assertOk();
    expect(collect($order->json('data.items'))->pluck('status', 'description')->all())->toMatchArray(['Antipasto misto' => 'ready', 'Risotto' => 'sent', 'Acqua' => 'sent']);

    $this->postJson("/api/v1/hospitality/orders/{$seated['order']}/move", ['table_id' => $this->otherTable])->assertOk();
    $this->postJson("/api/v1/hospitality/order-items/{$seated['items']['Acqua']}/void", ['reason' => 'Errore'])->assertForbidden();
    $this->postJson("/api/v1/hospitality/rooms/{$this->room}/tables", ['name' => 'T3'])->assertForbidden();
});

test('the bill is split by items and in equal shares, each part a POS sale, and the order closes when paid', function (): void {
    verticalApi($this->waiter, $this->tenant);
    $seated = ($this->seat)();
    $this->getJson("/api/v1/hospitality/orders/{$seated['order']}/bill")->assertOk()->assertJsonPath('data.remaining_gross', '82.16');

    $first = $this->postJson("/api/v1/hospitality/orders/{$seated['order']}/payments", [
        'pos_session_id' => $this->session->id, 'mode' => 'items', 'payment_method' => 'card',
        'lines' => [['order_item_id' => $seated['items']['Risotto'], 'quantity' => '1'], ['order_item_id' => $seated['items']['Acqua'], 'quantity' => '1']],
    ])->assertCreated();
    expect((float) PosSale::query()->findOrFail($first->json('data.pos_sale_id'))->total_net)->toBe(29.0);

    $this->postJson("/api/v1/hospitality/orders/{$seated['order']}/payments", [
        'pos_session_id' => $this->session->id, 'mode' => 'items', 'payment_method' => 'card', 'lines' => [['order_item_id' => $seated['items']['Risotto'], 'quantity' => '2']],
    ])->assertUnprocessable();

    $this->postJson("/api/v1/hospitality/orders/{$seated['order']}/payments", ['pos_session_id' => $this->session->id, 'mode' => 'equal', 'ways' => 2, 'payment_method' => 'cash'])->assertCreated();
    $this->getJson("/api/v1/hospitality/orders/{$seated['order']}")->assertJsonPath('data.status', 'open');
    $this->postJson("/api/v1/hospitality/orders/{$seated['order']}/payments", ['pos_session_id' => $this->session->id, 'mode' => 'equal', 'ways' => 1, 'payment_method' => 'cash'])->assertCreated();

    $this->getJson("/api/v1/hospitality/orders/{$seated['order']}")->assertJsonPath('data.status', 'closed');
    $this->getJson("/api/v1/hospitality/orders/{$seated['order']}/bill")->assertJsonPath('data.remaining_gross', '0.00');
    expect((float) PosSale::query()->sum('total_net'))->toBe(76.0)
        ->and(PosSale::query()->count())->toBe(3);
    $this->postJson("/api/v1/hospitality/orders/{$seated['order']}/items", ['items' => [['description' => 'Caffè', 'quantity' => '1', 'unit_price' => '3', 'tax_rate' => '8.1']]])->assertUnprocessable();
    $this->postJson('/api/v1/hospitality/orders', ['table_id' => $this->table, 'covers' => 1])->assertCreated();
});

test('a manager voids an unpaid item, and other companies and roles stay out', function (): void {
    $seated = ($this->seat)();
    $this->postJson("/api/v1/hospitality/order-items/{$seated['items']['Acqua']}/void", ['reason' => 'Ordinato per errore'])->assertOk()->assertJsonPath('data.status', 'voided');
    $this->getJson("/api/v1/hospitality/orders/{$seated['order']}/bill")->assertJsonPath('data.remaining_gross', '71.35');

    verticalApi(verticalMember($this->tenant, 'technician'), $this->tenant);
    $this->getJson('/api/v1/hospitality/rooms')->assertForbidden();
    verticalApi(verticalMember($this->tenant, 'accountant_readonly'), $this->tenant);
    $this->getJson("/api/v1/hospitality/orders/{$seated['order']}")->assertOk();
    $this->postJson("/api/v1/hospitality/orders/{$seated['order']}/fire", ['course' => 1])->assertForbidden();

    $other = verticalTenant();
    verticalApi(verticalMember($other, 'admin'), $other);
    $this->getJson("/api/v1/hospitality/orders/{$seated['order']}")->assertNotFound();
    $this->postJson('/api/v1/hospitality/orders', ['table_id' => $this->otherTable, 'covers' => 1])->assertUnprocessable()->assertJsonValidationErrors('table_id');
    $this->postJson("/api/v1/hospitality/orders/{$seated['order']}/payments", ['pos_session_id' => $this->session->id, 'mode' => 'equal', 'ways' => 1, 'payment_method' => 'cash'])->assertUnprocessable()->assertJsonValidationErrors('pos_session_id');
});
