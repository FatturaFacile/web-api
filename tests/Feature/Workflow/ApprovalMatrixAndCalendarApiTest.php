<?php

declare(strict_types=1);

use App\Services\Tenancy\TenantContext;
use Laravel\Sanctum\Sanctum;
use Modules\Workflow\Models\ApprovalMatrix;
use Modules\Workflow\Models\BusinessCalendar;
use Modules\Workflow\Models\BusinessCalendarHoliday;
use Modules\Workflow\Models\WorkflowDefinition;

require_once __DIR__.'/Support/ApprovalEngineHelpers.php';

beforeEach(function (): void {
    $this->tenant = wfTenant();
    $this->admin = wfMember($this->tenant, 'admin', 'Admin');
    $this->member = wfMember($this->tenant, 'member', 'Member');

    $this->as = function ($user = null) {
        Sanctum::actingAs($user ?? $this->admin);
        app(TenantContext::class)->clear();

        return $this->withHeader('X-Tenant-Id', (string) $this->tenant->id);
    };

    $this->payload = fn (array $override = []): array => [
        'name' => 'Purchase orders',
        'subject_type' => 'purchase_order',
        'currency' => 'CHF',
        'priority' => 10,
        'conditions' => [['field' => 'currency', 'operator' => 'eq', 'value' => 'CHF']],
        'tiers' => [
            ['tier_level' => 1, 'approver_type' => 'role', 'approver_value' => 'admin', 'threshold_min' => 0, 'threshold_max' => 5000],
            ['tier_level' => 2, 'approver_type' => 'role', 'approver_value' => 'owner', 'threshold_min' => 5000, 'threshold_max' => null],
        ],
        ...$override,
    ];
});

afterEach(fn () => app(TenantContext::class)->clear());

test('an administrator creates, reads, updates and deletes an approval matrix', function (): void {
    $id = ($this->as)()->postJson('/api/v1/workflows/matrices', ($this->payload)())->assertSuccessful()->json('data.id');

    ($this->as)()->getJson("/api/v1/workflows/matrices/{$id}")->assertOk()->assertJsonPath('data.name', 'Purchase orders')->assertJsonCount(2, 'data.tiers');
    ($this->as)()->getJson('/api/v1/workflows/matrices')->assertOk()->assertJsonCount(1, 'data');

    ($this->as)()->putJson("/api/v1/workflows/matrices/{$id}", ($this->payload)(['name' => 'Renamed', 'tiers' => [['tier_level' => 1, 'approver_type' => 'role', 'approver_value' => 'owner']]]))
        ->assertOk()->assertJsonPath('data.name', 'Renamed')->assertJsonCount(1, 'data.tiers');

    ($this->as)()->deleteJson("/api/v1/workflows/matrices/{$id}")->assertSuccessful();

    expect(ApprovalMatrix::query()->whereKey($id)->exists())->toBeFalse();
});

test('a plain member can read and simulate matrices but not change them', function (): void {
    ($this->as)()->postJson('/api/v1/workflows/matrices', ($this->payload)())->assertSuccessful();

    ($this->as)($this->member)->getJson('/api/v1/workflows/matrices')->assertOk();
    ($this->as)($this->member)->postJson('/api/v1/workflows/matrices', ($this->payload)())->assertForbidden();
    ($this->as)($this->member)->postJson('/api/v1/workflows/calendars', ['name' => 'Mine'])->assertForbidden();
});

test('the simulation shows the tiers of an amount without starting anything', function (): void {
    ($this->as)()->postJson('/api/v1/workflows/matrices', ($this->payload)())->assertSuccessful();

    ($this->as)($this->member)->postJson('/api/v1/workflows/matrices/simulate', [
        'subject_type' => 'purchase_order', 'attributes' => ['amount' => 8000, 'currency' => 'CHF'],
    ])->assertOk()->assertJsonPath('data.blocked', false)->assertJsonPath('data.auto_approved', false);

    ($this->as)($this->member)->postJson('/api/v1/workflows/matrices/simulate', ['subject_type' => 'purchase_order'])->assertUnprocessable();
});

test('a matrix needs a valid tier and a known operator', function (): void {
    ($this->as)()->postJson('/api/v1/workflows/matrices', ($this->payload)(['tiers' => [['tier_level' => 1, 'approver_type' => 'nobody']]]))->assertUnprocessable()->assertJsonValidationErrors('tiers.0.approver_type');
    ($this->as)()->postJson('/api/v1/workflows/matrices', ($this->payload)(['conditions' => [['field' => 'currency', 'operator' => 'maybe', 'value' => 'CHF']]]))->assertUnprocessable();
});

test('a calendar is created and the Swiss holidays of a canton are seeded', function (): void {
    $id = ($this->as)()->postJson('/api/v1/workflows/calendars', ['name' => 'Zurich', 'timezone' => 'Europe/Zurich'])->assertSuccessful()->json('data.id');

    ($this->as)()->postJson("/api/v1/workflows/calendars/{$id}/seed-holidays", ['country' => 'CH', 'year' => 2026, 'canton' => 'ZH'])->assertSuccessful();

    $dates = BusinessCalendarHoliday::query()->where('business_calendar_id', $id)->get()->map(fn ($holiday) => $holiday->holiday_date->toDateString());

    expect($dates)->toContain('2026-08-01')->toContain('2026-12-25')->toContain('2026-04-06');

    ($this->as)()->getJson("/api/v1/workflows/calendars/{$id}/holidays")->assertOk();
    ($this->as)()->postJson("/api/v1/workflows/calendars/{$id}/seed-holidays", ['country' => 'FR', 'year' => 2026])->assertUnprocessable();
});

test('a single holiday is added and removed', function (): void {
    $calendar = BusinessCalendar::query()->findOrFail(($this->as)()->postJson('/api/v1/workflows/calendars', ['name' => 'Plant'])->json('data.id'));

    $holidayId = ($this->as)()->postJson("/api/v1/workflows/calendars/{$calendar->id}/holidays", ['holiday_date' => '2026-06-15', 'name' => 'Company day'])->assertSuccessful()->json('data.id');

    expect(BusinessCalendarHoliday::query()->where('business_calendar_id', $calendar->id)->count())->toBe(1);

    ($this->as)()->deleteJson("/api/v1/workflows/calendars/{$calendar->id}/holidays/{$holidayId}")->assertSuccessful();

    expect(BusinessCalendarHoliday::query()->where('business_calendar_id', $calendar->id)->count())->toBe(0);
});

test('an update with a stale If-Match answers 409 and a fresh one succeeds', function (): void {
    wfDefinition($this->tenant, 'WF_LOCK', ['assignee_type' => 'direct_user', 'assignee_id' => $this->member->id]);
    $definition = WorkflowDefinition::query()->where('code', 'WF_LOCK')->firstOrFail();
    $definition->update(['status' => 'draft']);

    $show = ($this->as)()->getJson("/api/v1/workflows/definitions/{$definition->id}")->assertOk();
    $etag = $show->headers->get('ETag') ?? '"'.$show->json('data.lock_version').'"';

    ($this->as)()->withHeader('If-Match', $etag)->putJson("/api/v1/workflows/definitions/{$definition->id}", ['name' => 'First edit'])->assertOk();
    ($this->as)()->withHeader('If-Match', $etag)->putJson("/api/v1/workflows/definitions/{$definition->id}", ['name' => 'Second edit'])->assertStatus(409);

    expect($definition->refresh()->name)->toBe('First edit');
});
