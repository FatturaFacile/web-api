<?php

declare(strict_types=1);

use App\Models\Alert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\SubscriptionBilling\Models\Subscription;
use Tests\Feature\SubscriptionBilling\Support\SubscriptionScenario;

uses(RefreshDatabase::class);

/**
 * Server-side grid columns of the alert list and of the subscription contract list (filter, sort, search, values).
 */
beforeEach(function (): void {
    $this->travelTo('2026-03-15 09:00:00');
    $this->tenant = SubscriptionScenario::company();
    SubscriptionScenario::actingAs($this->tenant);
});

test('the alert list searches, filters by severity and serves grid columns', function (): void {
    foreach ([['Certificate expiring', 'critical', true], ['VAT payment due', 'warning', true], ['Rates synced', 'info', false]] as [$title, $severity, $active]) {
        Alert::query()->create(['tenant_id' => $this->tenant->id, 'title' => $title, 'message' => 'Body of '.$title, 'severity' => $severity, 'is_active' => $active]);
    }

    $this->getJson('/api/v1/alerts?search=vat')->assertOk()->assertJsonPath('meta.total', 1);
    $this->getJson('/api/v1/alerts?search=Body%20of')->assertOk()->assertJsonPath('meta.total', 3);
    $this->getJson('/api/v1/alerts?severity=critical')->assertOk()->assertJsonPath('meta.total', 1);

    $sorted = $this->getJson('/api/v1/alerts?sort_by=title&sort_dir=asc')->assertOk();
    expect(collect($sorted->json('data'))->pluck('title')->all())->toBe(['Certificate expiring', 'Rates synced', 'VAT payment due']);

    $inactive = $this->getJson('/api/v1/alerts?filter[is_active][]=0')->assertOk();
    expect($inactive->json('meta.total'))->toBe(1);

    $values = $this->getJson('/api/v1/alerts?grid_values=severity')->assertOk();
    expect(collect($values->json('data'))->pluck('count', 'value')->all())->toBe(['critical' => 1, 'info' => 1, 'warning' => 1]);
});

test('the contract list sorts, filters and lists values by customer and plan name', function (): void {
    $cheap = SubscriptionScenario::plan($this->tenant, ['name' => 'Alpha Plan']);
    $dear = SubscriptionScenario::plan($this->tenant, ['name' => 'Zeta Plan']);
    $first = SubscriptionScenario::customer($this->tenant);
    $second = SubscriptionScenario::customer($this->tenant);
    $first->update(['company_name' => 'Bianchi SpA']);
    $second->update(['company_name' => 'Verdi Srl']);

    $this->postJson('/api/v1/sub/contracts', ['customer_id' => $first->id, 'plan_id' => $dear->id, 'start_date' => '2026-03-15'])->assertCreated();
    $this->postJson('/api/v1/sub/contracts', ['customer_id' => $second->id, 'plan_id' => $cheap->id, 'start_date' => '2026-03-15'])->assertCreated();
    expect(Subscription::query()->count())->toBe(2);

    $byCustomer = $this->getJson('/api/v1/sub/contracts?sort_by=customer.name&sort_dir=asc')->assertOk();
    expect(collect($byCustomer->json('data'))->pluck('customer_name')->all())->toBe(['Bianchi SpA', 'Verdi Srl']);

    $byPlan = $this->getJson('/api/v1/sub/contracts?sort_by=plan.name&sort_dir=asc')->assertOk();
    expect(collect($byPlan->json('data'))->pluck('plan_name')->all())->toBe(['Alpha Plan', 'Zeta Plan']);

    $filtered = $this->getJson('/api/v1/sub/contracts?filter[plan.name][]=Zeta%20Plan')->assertOk();
    expect($filtered->json('meta.total'))->toBe(1)
        ->and($filtered->json('data.0.customer_name'))->toBe('Bianchi SpA');

    $like = $this->getJson('/api/v1/sub/contracts?filter_like[customer.name]=verdi')->assertOk();
    expect($like->json('meta.total'))->toBe(1);

    $values = $this->getJson('/api/v1/sub/contracts?grid_values=customer.name')->assertOk();
    expect(collect($values->json('data'))->pluck('value')->sort()->values()->all())->toBe(['Bianchi SpA', 'Verdi Srl']);

    $this->getJson('/api/v1/sub/contracts?search=zeta')->assertOk()->assertJsonPath('meta.total', 1);
    $this->getJson('/api/v1/sub/contracts?search=verdi')->assertOk()->assertJsonPath('meta.total', 1);
});
