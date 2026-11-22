<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Manufacturing\Models\MfgMachineCenter;

require_once __DIR__.'/ManufacturingSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    mfgSetUp($this);
    $this->product = mfgProductSetUp($this);
});

/**
 * A planned order of the pump through the API.
 */
function mfgRescheduleOrder(object $test, string $quantity): string
{
    return (string) $test->postJson('/api/v1/manufacturing/production-orders', [
        'mfg_item_id' => $test->product['fg']->id,
        'target_quantity' => $quantity,
        'planned_start_date' => now()->toDateString(),
        'planned_end_date' => now()->addDays(5)->toDateString(),
    ])->assertCreated()->json('data.id');
}

test('a reschedule is refused when the work centre cannot carry the load of the window, unless the overload is allowed', function (): void {
    // 100 pumps need 15.5 hours on the work centre: the first operation (10.5 h) books the first day, which has 8 hours.
    $big = mfgRescheduleOrder($this, '100');
    $this->postJson("/api/v1/manufacturing/production-orders/{$big}/release")->assertOk();

    $small = mfgRescheduleOrder($this, '10');
    $today = now()->toDateString();

    $this->postJson("/api/v1/manufacturing/production-orders/{$small}/reschedule", ['planned_start_date' => $today, 'planned_end_date' => $today])
        ->assertUnprocessable()->assertJsonValidationErrors(['planned_start_date']);
    expect($this->getJson("/api/v1/manufacturing/production-orders/{$small}")->json('data.planned_end_date'))->toBe(now()->addDays(5)->toDateString());

    $this->postJson("/api/v1/manufacturing/production-orders/{$small}/reschedule", ['planned_start_date' => $today, 'planned_end_date' => $today, 'allow_overload' => true])
        ->assertOk()->assertJsonPath('data.planned_end_date', $today);

    $this->postJson("/api/v1/manufacturing/production-orders/{$small}/reschedule", ['planned_start_date' => $today, 'planned_end_date' => now()->addDays(10)->toDateString()])
        ->assertOk();
});

test('a released order does not collide with its own booking and its operations move with its start date', function (): void {
    $id = mfgRescheduleOrder($this, '10');
    $released = $this->postJson("/api/v1/manufacturing/production-orders/{$id}/release")->assertOk();
    $before = collect($released->json('data.operations'))->pluck('scheduled_date', 'sequence')->all();

    $start = now()->addDays(3)->toDateString();
    $this->postJson("/api/v1/manufacturing/production-orders/{$id}/reschedule", ['planned_start_date' => $start, 'planned_end_date' => now()->addDays(13)->toDateString()])->assertOk();

    $after = collect($this->getJson("/api/v1/manufacturing/production-orders/{$id}")->json('data.operations'))->pluck('scheduled_date', 'sequence')->all();
    foreach ($before as $sequence => $date) {
        expect($after[$sequence])->toBe(now()->parse($date)->addDays(3)->toDateString());
    }

    // Moving it within its own window is not blocked by the load it already books itself.
    $this->postJson("/api/v1/manufacturing/production-orders/{$id}/reschedule", ['planned_start_date' => $start, 'planned_end_date' => now()->addDays(4)->toDateString()])->assertOk();
});

test('the machine stream sends the live states as server-sent events and ends at its deadline', function (): void {
    MfgMachineCenter::create([
        'tenant_id' => $this->tenant->id,
        'machine_code' => 'CNC-STREAM-01',
        'name' => 'Streamed machine',
        'work_center_id' => $this->product['wc']->id,
        'hourly_rate' => '95',
        'power_kw' => '22',
        'status' => 'active',
    ])->forceFill(['current_state' => 'FAULT', 'state_since' => now()])->save();

    $response = $this->get('/api/v1/manufacturing/machines/stream?seconds=1&interval=1');

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('text/event-stream');
    $body = $response->streamedContent();
    expect($body)->toContain('event: machines')
        ->and($body)->toContain('"FAULT":1')
        ->and($body)->toContain('CNC-STREAM-01');
});
