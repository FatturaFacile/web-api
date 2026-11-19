<?php

declare(strict_types=1);

namespace Tests\Unit\CRM;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Enterprise\EventBus\Models\TransactionalOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Modules\CRM\Contracts\LeadRoutingServiceInterface;
use Modules\CRM\Models\Lead;
use Modules\CRM\Models\LeadRoutingRule;
use Modules\CRM\Notifications\LeadAssignedNotification;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Notification::fake();

    $this->tenant = Tenant::create(['name' => 'Routing Tenant', 'legal_name' => 'Routing Tenant SA', 'country' => 'CH', 'default_currency' => 'CHF']);
    app(TenantContext::class)->setTenant($this->tenant);

    $this->routing = app(LeadRoutingServiceInterface::class);

    $this->makeUser = function (string $role = 'member', ?Tenant $tenant = null): User {
        $user = User::create(['name' => 'Rep '.Str::random(4), 'email' => Str::lower(Str::random(10)).'@routing.test', 'password' => bcrypt('secret')]);
        $user->tenants()->attach(($tenant ?? $this->tenant)->id, ['id' => (string) Str::uuid(), 'role' => $role]);

        return $user;
    };

    $this->makeLead = fn (array $attributes = []): Lead => Lead::create(array_merge([
        'tenant_id' => (string) $this->tenant->id,
        'first_name' => 'Gianni',
        'status' => 'new',
        'country' => 'CH',
    ], $attributes));

    $this->makeRule = fn (array $attributes): LeadRoutingRule => LeadRoutingRule::create(array_merge([
        'tenant_id' => (string) $this->tenant->id,
        'name' => 'Rule',
        'priority' => 100,
        'strategy' => 'round_robin',
        'is_default' => false,
        'is_active' => true,
    ], $attributes));
});

test('distributes leads fairly with round-robin: 3 reps and 6 leads give 2 each', function (): void {
    $reps = [($this->makeUser)(), ($this->makeUser)(), ($this->makeUser)()];
    ($this->makeRule)(['assignee_user_ids' => array_map(fn (User $user): string => (string) $user->id, $reps)]);

    foreach (range(1, 6) as $i) {
        $this->routing->route(($this->makeLead)());
    }

    $counts = Lead::where('tenant_id', $this->tenant->id)->get()->countBy('assigned_user_id');

    foreach ($reps as $rep) {
        expect($counts[(string) $rep->id] ?? 0)->toBe(2);
        Notification::assertSentToTimes($rep, LeadAssignedNotification::class, 2);
    }
});

test('uses the first matching rule by priority and evaluates conditions', function (): void {
    $ticino = ($this->makeUser)();
    $enterprise = ($this->makeUser)();
    $fallback = ($this->makeUser)();

    ($this->makeRule)(['name' => 'Enterprise', 'priority' => 10, 'strategy' => 'fixed', 'conditions' => ['employee_min' => 250], 'assignee_user_ids' => [(string) $enterprise->id]]);
    ($this->makeRule)(['name' => 'Ticino ERP', 'priority' => 20, 'strategy' => 'fixed', 'conditions' => ['countries' => ['ch'], 'regions' => ['TI'], 'product_lines' => ['ERP'], 'ratings' => ['warm', 'hot']], 'assignee_user_ids' => [(string) $ticino->id]]);
    ($this->makeRule)(['name' => 'Default', 'priority' => 1, 'strategy' => 'fixed', 'is_default' => true, 'assignee_user_ids' => [(string) $fallback->id]]);

    $big = $this->routing->route(($this->makeLead)(['employee_count' => 500, 'state_region' => 'TI', 'product_line' => 'erp', 'rating' => 'hot']));
    $ticinoLead = $this->routing->route(($this->makeLead)(['employee_count' => 20, 'state_region' => 'TI', 'product_line' => 'erp', 'rating' => 'warm']));
    $coldLead = $this->routing->route(($this->makeLead)(['state_region' => 'TI', 'product_line' => 'erp', 'rating' => 'cold']));

    expect($big->assignedUserId)->toBe((string) $enterprise->id)
        ->and($big->usedDefaultRule)->toBeFalse()
        ->and($ticinoLead->assignedUserId)->toBe((string) $ticino->id)
        ->and($coldLead->assignedUserId)->toBe((string) $fallback->id)
        ->and($coldLead->usedDefaultRule)->toBeTrue();
});

test('leaves the lead unassigned and notifies admins when nothing matches', function (): void {
    $admin = ($this->makeUser)('admin');
    $member = ($this->makeUser)('member');
    ($this->makeRule)(['conditions' => ['countries' => ['IT']], 'assignee_user_ids' => [(string) $member->id]]);

    $lead = ($this->makeLead)(['assigned_user_id' => (string) $member->id]);
    $result = $this->routing->route($lead);

    expect($result->isAssigned())->toBeFalse()
        ->and($lead->fresh()->assigned_user_id)->toBeNull();

    Notification::assertSentTo($admin, LeadAssignedNotification::class, fn (LeadAssignedNotification $notification): bool => $notification->unassigned);
    Notification::assertNotSentTo($member, LeadAssignedNotification::class);

    expect(TransactionalOutbox::where('event_type', 'crm.lead.assigned')->where('aggregate_id', (string) $lead->id)->exists())->toBeTrue();
});

test('skips assignees that no longer belong to the tenant', function (): void {
    $otherTenant = Tenant::create(['name' => 'Other', 'legal_name' => 'Other Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    $outsider = ($this->makeUser)('member', $otherTenant);
    $insider = ($this->makeUser)();

    ($this->makeRule)(['assignee_user_ids' => [(string) $outsider->id, (string) $insider->id]]);

    $first = $this->routing->route(($this->makeLead)());
    $second = $this->routing->route(($this->makeLead)());

    expect($first->assignedUserId)->toBe((string) $insider->id)
        ->and($second->assignedUserId)->toBe((string) $insider->id);
});

test('ignores inactive rules and reorders priorities', function (): void {
    $rep = ($this->makeUser)();
    $inactive = ($this->makeRule)(['name' => 'Off', 'priority' => 1, 'is_active' => false, 'assignee_user_ids' => [(string) $rep->id]]);
    $a = ($this->makeRule)(['name' => 'A', 'priority' => 5, 'conditions' => ['countries' => ['IT']], 'assignee_user_ids' => [(string) $rep->id]]);
    $b = ($this->makeRule)(['name' => 'B', 'priority' => 6, 'conditions' => ['countries' => ['DE']], 'assignee_user_ids' => [(string) $rep->id]]);

    expect($this->routing->route(($this->makeLead)())->isAssigned())->toBeFalse();

    $this->routing->reorder((string) $this->tenant->id, [(string) $b->id, (string) $a->id]);

    expect($b->fresh()->priority)->toBe(10)
        ->and($a->fresh()->priority)->toBe(20)
        ->and($inactive->fresh()->priority)->toBe(30);
});

test('matches conditions with employee bounds and missing values', function (): void {
    $lead = new Lead(['country' => 'IT', 'employee_count' => 40, 'rating' => 'warm']);

    expect($this->routing->matches($lead, null))->toBeTrue()
        ->and($this->routing->matches($lead, ['employee_min' => 10, 'employee_max' => 50]))->toBeTrue()
        ->and($this->routing->matches($lead, ['employee_min' => 41]))->toBeFalse()
        ->and($this->routing->matches($lead, ['regions' => ['MI']]))->toBeFalse()
        ->and($this->routing->matches($lead, ['countries' => [], 'ratings' => ['WARM']]))->toBeTrue()
        ->and($this->routing->matches(new Lead(['country' => 'IT']), ['employee_max' => 100]))->toBeFalse();
});
