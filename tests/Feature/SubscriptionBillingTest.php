<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2026-03-15 10:00:00');

    $this->tenant = Tenant::create([
        'name' => 'FatturaFacile Italy SRL',
        'legal_name' => 'FatturaFacile Italy SRL',
        'vat_number' => '00811720580',
        'country' => 'IT',
        'default_currency' => 'EUR',
    ]);

    $user = User::create([
        'name' => 'Leonardo',
        'email' => 'leo@fatturafacile.com',
        'password' => bcrypt('secret123'),
        'locale' => 'it',
    ]);
    $user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    Sanctum::actingAs($user);

    $this->contact = Contact::create([
        'tenant_id' => $this->tenant->id,
        'company_name' => 'Milan Customer SpA',
        'country' => 'IT',
        'payment_terms_days' => 30,
    ]);
});

function subscriptionPayload(array $overrides = []): array
{
    return array_merge([
        'contact_id' => test()->contact->id,
        'name' => 'Annual hosting',
        'interval' => 'monthly',
        'currency' => 'EUR',
        'start_date' => '2026-01-15',
        'items' => [['description' => 'Hosting', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 22]],
    ], $overrides);
}

function subscriptionsApi()
{
    return test()->withHeader('X-Tenant-ID', test()->tenant->id);
}

test('subscription is created active with number and first billing date', function (): void {
    $response = subscriptionsApi()->postJson('/api/v1/subscriptions', subscriptionPayload())->assertCreated();

    expect($response->json('data.subscription_number'))->toBe('SUB-2026-0001')
        ->and($response->json('data.status'))->toBe('active')
        ->and($response->json('data.next_billing_date'))->toStartWith('2026-01-15')
        ->and($response->json('data.items'))->toHaveCount(1);
});

test('subscription validation rejects bad interval and end date before start', function (): void {
    subscriptionsApi()->postJson('/api/v1/subscriptions', subscriptionPayload(['interval' => 'weekly']))
        ->assertUnprocessable()->assertJsonValidationErrors('interval');
    subscriptionsApi()->postJson('/api/v1/subscriptions', subscriptionPayload(['end_date' => '2025-12-31']))
        ->assertUnprocessable()->assertJsonValidationErrors('end_date');
});

test('billing catches up missed periods, is idempotent and sets invoice dates and due dates', function (): void {
    $id = subscriptionsApi()->postJson('/api/v1/subscriptions', subscriptionPayload())->json('data.id');

    $bill = subscriptionsApi()->postJson("/api/v1/subscriptions/{$id}/bill")->assertOk();
    expect($bill->json('data.invoices_created'))->toBe(3);

    $invoices = Invoice::where('subscription_id', $id)->orderBy('date')->get();
    expect($invoices->pluck('date')->map->toDateString()->all())->toBe(['2026-01-15', '2026-02-15', '2026-03-15'])
        ->and($invoices->pluck('invoice_number')->all())->toBe(['INV-2026-0001', 'INV-2026-0002', 'INV-2026-0003'])
        ->and((float) $invoices->first()->total_gross)->toBe(122.0)
        ->and($invoices->first()->due_date->toDateString())->toBe('2026-02-14');

    subscriptionsApi()->postJson("/api/v1/subscriptions/{$id}/bill")->assertOk()->assertJsonPath('data.invoices_created', 0);

    $show = subscriptionsApi()->getJson("/api/v1/subscriptions/{$id}")->json('data');
    expect($show['next_billing_date'])->toStartWith('2026-04-15')->and($show['runs'])->toHaveCount(3);
});

test('paused subscriptions are not billed until resumed, cancelled ones never', function (): void {
    $id = subscriptionsApi()->postJson('/api/v1/subscriptions', subscriptionPayload())->json('data.id');

    subscriptionsApi()->postJson("/api/v1/subscriptions/{$id}/pause")->assertOk();
    subscriptionsApi()->postJson("/api/v1/subscriptions/{$id}/pause")->assertUnprocessable();
    subscriptionsApi()->postJson("/api/v1/subscriptions/{$id}/bill")->assertJsonPath('data.invoices_created', 0);

    subscriptionsApi()->postJson("/api/v1/subscriptions/{$id}/resume")->assertOk();
    subscriptionsApi()->postJson("/api/v1/subscriptions/{$id}/cancel")->assertOk();
    subscriptionsApi()->postJson("/api/v1/subscriptions/{$id}/bill")->assertJsonPath('data.invoices_created', 0);
    subscriptionsApi()->postJson("/api/v1/subscriptions/{$id}/resume")->assertUnprocessable();
});

test('subscription expires after its end date unless auto-renew is on', function (): void {
    $expiring = subscriptionsApi()->postJson('/api/v1/subscriptions', subscriptionPayload(['end_date' => '2026-02-28']))->json('data.id');
    $renewing = subscriptionsApi()->postJson('/api/v1/subscriptions', subscriptionPayload(['end_date' => '2026-02-28', 'auto_renew' => true]))->json('data.id');

    expect(subscriptionsApi()->postJson("/api/v1/subscriptions/{$expiring}/bill")->json('data.invoices_created'))->toBe(2);
    expect(subscriptionsApi()->getJson("/api/v1/subscriptions/{$expiring}")->json('data.status'))->toBe('expired');

    expect(subscriptionsApi()->postJson("/api/v1/subscriptions/{$renewing}/bill")->json('data.invoices_created'))->toBe(3);
    expect(subscriptionsApi()->getJson("/api/v1/subscriptions/{$renewing}")->json('data.status'))->toBe('active');
});

test('month-end start dates do not overflow', function (): void {
    $this->travelTo('2026-03-31 08:00:00');
    $id = subscriptionsApi()->postJson('/api/v1/subscriptions', subscriptionPayload(['start_date' => '2026-01-31']))->json('data.id');

    subscriptionsApi()->postJson("/api/v1/subscriptions/{$id}/bill")->assertJsonPath('data.invoices_created', 3);
    expect(Invoice::where('subscription_id', $id)->orderBy('date')->get()->pluck('date')->map->toDateString()->all())
        ->toBe(['2026-01-31', '2026-02-28', '2026-03-31']);
});

test('scheduled command bills due subscriptions across tenants and sends renewal notices once', function (): void {
    $id = subscriptionsApi()->postJson('/api/v1/subscriptions', subscriptionPayload([
        'start_date' => '2026-03-15', 'end_date' => '2026-05-10', 'notify_days_before' => 60,
    ]))->json('data.id');

    $this->artisan('subscriptions:process')->assertSuccessful();
    $this->artisan('subscriptions:process')->assertSuccessful();

    expect(Invoice::withoutGlobalScopes()->where('subscription_id', $id)->count())->toBe(1);

    $events = DB::table('document_events')->where('eventable_id', $id)->pluck('event')->all();
    expect(array_count_values($events)['renewal_notice'])->toBe(1);
});
