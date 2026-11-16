<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Modules\ServiceManagement\Models\DepotRepairRmaOrder;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 11, 15)->setTime(9, 0));

    $this->tenant = Tenant::factory()->create(['name' => 'Repair Lab', 'legal_name' => 'Repair Lab SA', 'country' => 'CH']);
    app(TenantContext::class)->setTenant($this->tenant);
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);

    $customer = Contact::factory()->create(['tenant_id' => $this->tenant->id, 'country' => 'IT', 'vat_number' => 'IT01234567890']);

    $this->postJson('/api/v1/esm/equipment-passports', [
        'serial_number' => 'SN-PUB-1',
        'current_owner_customer_id' => $customer->id,
        'delivery_date' => '2023-01-10',
    ])->assertCreated();

    /**
     * Issue an out-of-warranty RMA, receive it and send a 105.00 estimate above the 50.00 threshold.
     *
     * @return array{rma_id: string, rma_number: string, tracking_token: string, estimate_token: string}
     */
    $this->pendingEstimate = function (): array {
        $issue = $this->postJson('/api/v1/esm/rma-orders', [
            'serial_number' => 'SN-PUB-1',
            'reported_symptom' => 'Display flickers',
            'preauthorized_threshold' => 50,
        ])->assertCreated();
        $rmaId = (string) $issue->json('data.id');

        $this->postJson("/api/v1/esm/rma-orders/{$rmaId}/receive")->assertOk();
        $diagnosis = $this->postJson("/api/v1/esm/rma-orders/{$rmaId}/diagnosis", [
            'lab_bench_diagnostic_notes' => 'INTERNAL: inverter cold joint, margin ok',
            'repair_feasible' => true,
            'lines' => [
                ['line_type' => 'part', 'description' => 'Backlight inverter', 'qty' => 1, 'unit_cost' => 20, 'unit_price' => 60],
                ['line_type' => 'labour', 'description' => 'Bench labour', 'qty' => 0.5, 'unit_cost' => 35, 'unit_price' => 90],
            ],
        ])->assertOk();

        return [
            'rma_id' => $rmaId,
            'rma_number' => (string) $issue->json('data.rma_number'),
            'tracking_token' => (string) $issue->json('data.label.tracking_token'),
            'estimate_token' => (string) $diagnosis->json('data.estimate_token'),
        ];
    };

    $this->asAnonymousCustomer = function (): void {
        app('auth')->forgetGuards();
        app(TenantContext::class)->clear();
    };
});

test('public RMA routes are registered without sanctum', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/public/rma'));

    expect($routes)->toHaveCount(4);
    foreach ($routes as $route) {
        expect($route->gatherMiddleware())->not->toContain('auth:sanctum')
            ->and($route->gatherMiddleware())->toContain('throttle:30,1');
    }
});

test('the public estimate shows prices but no internal cost or lab notes', function () {
    $pending = ($this->pendingEstimate)();
    ($this->asAnonymousCustomer)();

    $response = $this->getJson('/api/v1/public/rma/estimates/'.$pending['estimate_token']);

    $response->assertOk()
        ->assertJsonPath('data.rma_number', $pending['rma_number'])
        ->assertJsonPath('data.status', 'awaiting_estimate_approval')
        ->assertJsonPath('data.serial_number', 'SN-PUB-1')
        ->assertJsonPath('data.total', '105.00')
        ->assertJsonPath('data.decision', null)
        ->assertJsonPath('data.company_name', 'Repair Lab SA')
        ->assertJsonCount(2, 'data.lines')
        ->assertJsonMissingPath('data.lines.0.unit_cost')
        ->assertJsonMissingPath('data.lab_bench_diagnostic_notes');

    expect($response->getContent())->not->toContain('INTERNAL')
        ->and($response->getContent())->not->toContain('unit_cost');
});

test('a valid estimate token accepts the estimate and a repeated decision returns 409', function () {
    $pending = ($this->pendingEstimate)();
    ($this->asAnonymousCustomer)();

    $this->postJson('/api/v1/public/rma/estimates/'.$pending['estimate_token'].'/accept', ['accepted_by_name' => 'Mario Rossi'])
        ->assertOk()
        ->assertJsonPath('data.decision', 'approved')
        ->assertJsonPath('data.status', 'in_repair_testing');

    $this->postJson('/api/v1/public/rma/estimates/'.$pending['estimate_token'].'/accept', ['accepted_by_name' => 'Mario Rossi'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'already_decided');

    app(TenantContext::class)->setTenant($this->tenant);
    $rma = DepotRepairRmaOrder::query()->findOrFail($pending['rma_id']);
    expect($rma->estimate_approval_status)->toBe('approved')
        ->and($rma->estimate_decided_via)->toBe('public_link')
        ->and($rma->estimate_decided_by_name)->toBe('Mario Rossi')
        ->and($rma->history()->where('actor_type', 'customer')->count())->toBe(1);
});

test('the customer can decline the estimate and the unit goes back unrepaired', function () {
    $pending = ($this->pendingEstimate)();
    ($this->asAnonymousCustomer)();

    $this->postJson('/api/v1/public/rma/estimates/'.$pending['estimate_token'].'/decline', [
        'declined_by_name' => 'Mario Rossi',
        'reason' => 'Too expensive',
    ])->assertOk()
        ->assertJsonPath('data.decision', 'declined')
        ->assertJsonPath('data.status', 'ready_for_return');

    $this->postJson('/api/v1/public/rma/estimates/'.$pending['estimate_token'].'/decline', ['declined_by_name' => 'Mario Rossi'])
        ->assertStatus(409);

    $this->getJson('/api/v1/public/rma/estimates/'.$pending['estimate_token'])
        ->assertOk()
        ->assertJsonPath('data.decision', 'declined');

    app(TenantContext::class)->setTenant($this->tenant);
    expect(DepotRepairRmaOrder::query()->findOrFail($pending['rma_id'])->estimate_approval_status)->toBe('declined_return_unrepaired');
});

test('wrong or expired estimate tokens return 404', function () {
    $pending = ($this->pendingEstimate)();
    ($this->asAnonymousCustomer)();

    $this->getJson('/api/v1/public/rma/estimates/'.str_repeat('a', 64))->assertNotFound();
    $this->postJson('/api/v1/public/rma/estimates/'.str_repeat('b', 64).'/accept', ['accepted_by_name' => 'X'])->assertNotFound();

    $this->travel(31)->days();

    $this->getJson('/api/v1/public/rma/estimates/'.$pending['estimate_token'])->assertNotFound();
    $this->postJson('/api/v1/public/rma/estimates/'.$pending['estimate_token'].'/accept', ['accepted_by_name' => 'X'])->assertNotFound();
});

test('public tracking requires the matching RMA number and tracking token', function () {
    $pending = ($this->pendingEstimate)();
    ($this->asAnonymousCustomer)();

    $response = $this->getJson('/api/v1/public/rma/tracking?'.http_build_query([
        'rma_number' => $pending['rma_number'],
        'token' => $pending['tracking_token'],
    ]));

    $response->assertOk()
        ->assertJsonPath('data.rma_number', $pending['rma_number'])
        ->assertJsonPath('data.status', 'awaiting_estimate_approval')
        ->assertJsonPath('data.status_label', 'Attesa approvazione preventivo')
        ->assertJsonPath('data.estimate_pending', true)
        ->assertJsonCount(8, 'data.steps');

    $steps = collect($response->json('data.steps'))->keyBy('status');
    expect($steps['awaiting_arrival']['reached_at'])->not->toBeNull()
        ->and($steps['awaiting_estimate_approval']['current'])->toBeTrue()
        ->and($steps['returned']['reached_at'])->toBeNull();

    $this->getJson('/api/v1/public/rma/tracking?'.http_build_query(['rma_number' => $pending['rma_number'], 'token' => str_repeat('c', 64)]))
        ->assertNotFound();
    $this->getJson('/api/v1/public/rma/tracking?'.http_build_query(['rma_number' => 'RMA-2026-999999', 'token' => $pending['tracking_token']]))
        ->assertNotFound();
});

test('a regenerated tracking token replaces the previous one', function () {
    $pending = ($this->pendingEstimate)();

    $new = $this->postJson("/api/v1/esm/rma-orders/{$pending['rma_id']}/tracking-token")
        ->assertOk()
        ->json('data.tracking_token');

    ($this->asAnonymousCustomer)();

    $this->getJson('/api/v1/public/rma/tracking?'.http_build_query(['rma_number' => $pending['rma_number'], 'token' => $pending['tracking_token']]))
        ->assertNotFound();
    $this->getJson('/api/v1/public/rma/tracking?'.http_build_query(['rma_number' => $pending['rma_number'], 'token' => $new]))
        ->assertOk();
});
