<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Subscription as LegacySubscription;
use App\Models\SubscriptionItem as LegacyItem;
use App\Models\SubscriptionRun as LegacyRun;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\SubscriptionBilling\Models\BillingRun;
use Modules\SubscriptionBilling\Models\InvoiceSchedule;
use Modules\SubscriptionBilling\Models\RevenueContract;
use Modules\SubscriptionBilling\Models\Subscription;
use Modules\SubscriptionBilling\Services\LegacySubscriptionMigrator;
use Tests\Feature\SubscriptionBilling\Support\SubscriptionScenario;

uses(RefreshDatabase::class);

/**
 * Access rules of every endpoint (read-only roles, authentication, tenant isolation), the deprecated legacy API on the
 * engine, and the idempotent migration of the legacy rows.
 */
beforeEach(function (): void {
    $this->travelTo('2026-03-15 09:00:00');
    $this->tenant = SubscriptionScenario::company();
    $this->admin = SubscriptionScenario::actingAs($this->tenant);
    $this->customer = SubscriptionScenario::customer($this->tenant);
    $this->plan = SubscriptionScenario::plan($this->tenant);
    $this->contract = $this->postJson('/api/v1/sub/contracts', ['customer_id' => $this->customer->id, 'plan_id' => $this->plan->id, 'start_date' => '2026-03-01'])->json('data.id');
});

/**
 * @return list<array{0: string, 1: string}>
 */
function subscriptionWriteRoutes(): array
{
    return [
        ['POST', '/api/v1/subscriptions/plans'], ['PUT', '/api/v1/subscriptions/plans/{plan}'], ['POST', '/api/v1/subscriptions/usage'],
        ['POST', '/api/v1/subscriptions/billing-runs'], ['POST', '/api/v1/subscriptions/revenue-recognition/recognize'],
        ['PUT', '/api/v1/subscriptions/dunning-policy'], ['POST', '/api/v1/subscriptions/dunning/run'], ['POST', '/api/v1/sub/contracts'],
        ['POST', '/api/v1/sub/contracts/{id}/cancel'], ['POST', '/api/v1/sub/contracts/{id}/upgrade'], ['POST', '/api/v1/sub/contracts/{id}/downgrade'],
        ['POST', '/api/v1/sub/contracts/{id}/quantity'], ['POST', '/api/v1/sub/contracts/{id}/activate'], ['POST', '/api/v1/sub/contracts/{id}/suspend'],
        ['POST', '/api/v1/sub/contracts/{id}/pause'], ['POST', '/api/v1/sub/contracts/{id}/reactivate'], ['POST', '/api/v1/sub/contracts/{id}/bill'],
        ['POST', '/api/v1/sub/contracts/{id}/collection-failures'], ['POST', '/api/v1/subscriptions'], ['PUT', '/api/v1/subscriptions/{id}'],
        ['DELETE', '/api/v1/subscriptions/{id}'], ['POST', '/api/v1/subscriptions/{id}/pause'], ['POST', '/api/v1/subscriptions/{id}/resume'],
        ['POST', '/api/v1/subscriptions/{id}/cancel'], ['POST', '/api/v1/subscriptions/{id}/bill'],
    ];
}

/**
 * @return list<array{0: string, 1: string}>
 */
function subscriptionReadRoutes(): array
{
    return [
        ['GET', '/api/v1/subscriptions/plans'], ['GET', '/api/v1/subscriptions/plans/{plan}'], ['GET', '/api/v1/subscriptions/usage'],
        ['GET', '/api/v1/subscriptions/billing-runs'], ['GET', '/api/v1/subscriptions/billing-runs/{run}'], ['GET', '/api/v1/subscriptions/revenue-contracts'],
        ['GET', '/api/v1/subscriptions/revenue-contracts/{revenue}'], ['GET', '/api/v1/subscriptions/metrics'], ['GET', '/api/v1/subscriptions/forecast'],
        ['GET', '/api/v1/subscriptions/dunning-policy'], ['GET', '/api/v1/sub/contracts'], ['GET', '/api/v1/sub/contracts/{id}'],
        ['GET', '/api/v1/sub/contracts/{id}/invoices'], ['GET', '/api/v1/sub/contracts/{id}/dunning'], ['GET', '/api/v1/sub/contracts/{id}/proration-preview'],
        ['GET', '/api/v1/subscriptions'], ['GET', '/api/v1/subscriptions/{id}'], ['GET', '/api/v1/subscriptions/{id}/runs'],
    ];
}

function subscriptionUri(string $uri): string
{
    test()->postJson('/api/v1/subscriptions/billing-runs');
    $run = BillingRun::query()->latest()->value('id');
    $revenue = RevenueContract::query()->value('id');

    return strtr($uri, ['{id}' => test()->contract, '{plan}' => test()->plan->id, '{run}' => (string) $run, '{revenue}' => (string) $revenue]);
}

test('read-only roles are refused every write and can read', function (): void {
    $uris = array_map(fn (array $route): array => [$route[0], subscriptionUri($route[1])], [...subscriptionWriteRoutes(), ...subscriptionReadRoutes()]);
    SubscriptionScenario::actingAs($this->tenant, 'accountant_readonly');

    foreach (array_slice($uris, 0, count(subscriptionWriteRoutes())) as [$method, $uri]) {
        expect($this->json($method, $uri, [])->status())->toBe(403, "{$method} {$uri}");
    }

    foreach (array_slice($uris, count(subscriptionWriteRoutes())) as [$method, $uri]) {
        expect($this->json($method, $uri)->status())->toBe(200, "{$method} {$uri}");
    }

    expect(Subscription::query()->find($this->contract)->status)->toBe('active');
});

test('every route needs authentication', function (): void {
    $uris = array_map(fn (array $route): array => [$route[0], subscriptionUri($route[1])], [...subscriptionWriteRoutes(), ...subscriptionReadRoutes()]);
    $this->app['auth']->forgetGuards();
    $this->flushHeaders();

    foreach ($uris as [$method, $uri]) {
        expect($this->json($method, $uri)->status())->toBe(401, "{$method} {$uri}");
    }
});

test('another company sees none of the records of this one', function (): void {
    $uris = array_map(fn (array $route): array => [$route[0], subscriptionUri($route[1])], subscriptionReadRoutes());
    $writes = array_map(fn (array $route): array => [$route[0], subscriptionUri($route[1])], array_filter(subscriptionWriteRoutes(), fn (array $route): bool => str_contains($route[1], '{')));
    $other = SubscriptionScenario::company('Concorrente Srl');
    SubscriptionScenario::actingAs($other);

    foreach ($uris as [$method, $uri]) {
        $response = $this->json($method, $uri);

        if (str_contains($uri, $this->contract) || str_contains($uri, $this->plan->id) || preg_match('/[0-9a-f]{8}-/', $uri)) {
            expect($response->status())->toBe(404, "{$method} {$uri}");
        } else {
            expect(collect($response->json('data'))->pluck('id')->filter()->all())->not->toContain($this->contract);
        }
    }

    foreach ($writes as [$method, $uri]) {
        expect($this->json($method, $uri, ['reason' => 'x', 'new_plan_id' => $this->plan->id, 'quantity' => 2, 'invoice_id' => (string) Str::uuid(), 'name' => 'x'])->status())->toBeIn([404, 422], "{$method} {$uri}");
    }

    app(TenantContext::class)->setTenant($this->tenant);
    expect(Subscription::query()->find($this->contract)->status)->toBe('active');
});

test('the deprecated API answers with the deprecation headers and keeps its shape', function (): void {
    $legacy = $this->postJson('/api/v1/subscriptions', [
        'contact_id' => $this->customer->id, 'name' => 'Hosting', 'interval' => 'quarterly', 'currency' => 'EUR', 'start_date' => '2026-01-10',
        'items' => [['description' => 'Hosting', 'quantity' => 2, 'unit_price' => 50, 'tax_rate' => 22]],
    ])->assertCreated()->assertHeader('X-Deprecated', 'true')->assertHeader('Link', '</api/v1/sub/contracts>; rel="successor-version"');
    $id = $legacy->json('data.id');

    expect($legacy->json('data.interval'))->toBe('quarterly')->and($legacy->json('data.items.0.line_total'))->toBe('100.00');
    $this->getJson('/api/v1/subscriptions?per_page=5')->assertOk()->assertJsonPath('meta.total', 2);
    $this->putJson("/api/v1/subscriptions/{$id}", ['name' => 'Hosting Plus', 'items' => [['description' => 'Hosting Plus', 'quantity' => 1, 'unit_price' => 150, 'tax_rate' => 22]]])->assertOk()
        ->assertJsonPath('data.name', 'Hosting Plus')->assertJsonPath('data.items.0.unit_price', '150.0000');
    $this->postJson("/api/v1/subscriptions/{$id}/bill")->assertOk()->assertJsonPath('data.invoices_created', 1);
    $this->putJson("/api/v1/subscriptions/{$id}", ['start_date' => '2026-02-01'])->assertUnprocessable()->assertJsonValidationErrors('start_date');
    $this->getJson("/api/v1/subscriptions/{$id}/runs")->assertOk()->assertJsonPath('data.0.status', 'success');
    $this->getJson("/api/v1/sub/contracts/{$id}")->assertOk()->assertJsonPath('data.mrr', '50.00');
    $this->deleteJson("/api/v1/subscriptions/{$id}")->assertOk();
    $this->getJson("/api/v1/subscriptions/{$id}")->assertNotFound();
    $this->getJson('/api/v1/subscriptions/not-a-uuid')->assertNotFound();
});

test('legacy subscriptions are copied once into the engine with their billed periods, never billed twice', function (): void {
    $contact = Contact::withoutGlobalScopes()->findOrFail($this->customer->id);
    $legacy = LegacySubscription::withoutGlobalScopes()->create([
        'tenant_id' => $this->tenant->id, 'contact_id' => $contact->id, 'subscription_number' => 'SUB-2025-0042', 'name' => 'Vecchio abbonamento',
        'status' => 'active', 'interval' => 'monthly', 'interval_count' => 1, 'currency' => 'EUR', 'start_date' => '2026-01-05',
        'next_billing_date' => '2026-03-05', 'auto_renew' => false, 'notify_days_before' => 15,
    ]);
    LegacyItem::create(['subscription_id' => $legacy->id, 'description' => 'Canone', 'quantity' => 1, 'unit_price' => 80, 'tax_rate' => 22, 'line_total' => 80]);
    foreach (['2026-01-05', '2026-02-05'] as $start) {
        LegacyRun::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'subscription_id' => $legacy->id, 'period_start' => $start, 'period_end' => date('Y-m-d', strtotime($start.' +1 month -1 day')), 'status' => 'success']);
    }
    $generic = LegacySubscription::withoutGlobalScopes()->create(['tenant_id' => null, 'contact_id' => $contact->id, 'subscription_number' => 'SUB-X', 'name' => 'Generic', 'status' => 'active', 'interval' => 'monthly', 'currency' => 'EUR', 'start_date' => '2026-01-01']);
    // A row without a company (the generic data of the installation) cannot be copied: it is skipped.
    DB::table('subscriptions')->where('id', $generic->id)->update(['tenant_id' => null]);

    $first = app(LegacySubscriptionMigrator::class)->migrate();
    $second = app(LegacySubscriptionMigrator::class)->migrate();

    expect($first)->toMatchArray(['migrated' => 1, 'skipped_without_tenant' => 1])
        ->and($second)->toMatchArray(['migrated' => 0, 'already_migrated' => 1])
        ->and(LegacySubscription::withoutGlobalScopes()->count())->toBe(2);

    $copy = Subscription::query()->findOrFail($legacy->id);
    expect($copy->legacy_subscription_id)->toBe((string) $legacy->id)
        ->and($copy->subscription_number)->toBe('SUB-2025-0042')
        ->and($copy->current_period_start->toDateString())->toBe('2026-03-05')
        ->and((string) $copy->mrr)->toBe('80.00');

    $this->postJson("/api/v1/sub/contracts/{$copy->id}/bill")->assertOk()->assertJsonCount(1, 'data');
    // The two legacy runs (no invoice kept in this fixture) stay as history; only March is billed by the engine.
    expect(InvoiceSchedule::query()->where('subscription_id', $copy->id)->count())->toBe(3)
        ->and(InvoiceSchedule::query()->where('subscription_id', $copy->id)->where('status', 'invoiced')->get()->map(fn ($s) => $s->service_period_start->toDateString())->all())->toBe(['2026-03-05'])
        ->and(Invoice::withoutGlobalScopes()->where('subscription_id', $legacy->id)->count())->toBe(1);

    $this->artisan('subscriptions:migrate-legacy')->assertSuccessful();
});
