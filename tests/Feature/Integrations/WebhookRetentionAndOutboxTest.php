<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Tenant;
use Enterprise\EventBus\Jobs\RelayOutboxEventsJob;
use Enterprise\EventBus\Models\TransactionalOutbox;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Integrations\Models\WebhookDelivery;
use Modules\Integrations\Models\WebhookDeliveryAttempt;
use Symfony\Component\Finder\Finder;

require_once __DIR__.'/WebhookHelpers.php';

uses(RefreshDatabase::class);

// ------------------------------------------------------------------------------------------------------ retention

test('the retention policy deletes each class of delivery after its own period and never an open one', function (): void {
    $tenant = complianceTenant();
    $endpoint = webhookEndpointFor($tenant);
    $make = fn (string $status, int $ageDays, bool $test = false): WebhookDelivery => tap(webhookPendingDelivery($endpoint, ['status' => $status, 'is_test' => $test]), fn (WebhookDelivery $delivery) => DB::table('webhook_deliveries')->where('id', $delivery->id)->update(['updated_at' => now()->subDays($ageDays)]));

    $keep = [
        'succeeded 29d' => $make('succeeded', 29),
        'dead 89d' => $make('dead', 89),
        'dead 40d' => $make('dead', 40),
        'test 6d' => $make('succeeded', 6, true),
        'pending 400d' => $make('pending', 400),
        'retrying 400d' => $make('retrying', 400),
    ];
    $drop = [
        'succeeded 31d' => $make('succeeded', 31),
        'dead 91d' => $make('dead', 91),
        'test succeeded 8d' => $make('succeeded', 8, true),
        'test dead 8d' => $make('dead', 8, true),
    ];

    WebhookDeliveryAttempt::create(['tenant_id' => $tenant->id, 'webhook_delivery_id' => $drop['dead 91d']->id, 'attempt_number' => 1, 'succeeded' => false, 'duration_ms' => 5, 'created_at' => now()]);

    $this->artisan('webhooks:prune')->expectsOutputToContain('Deleted 1 succeeded, 1 dead and 2 test')->assertSuccessful();

    $left = WebhookDelivery::withoutGlobalScopes()->pluck('id')->all();

    foreach ($keep as $label => $delivery) {
        expect(in_array($delivery->id, $left, true))->toBeTrue("{$label} must be kept");
    }

    foreach ($drop as $label => $delivery) {
        expect(in_array($delivery->id, $left, true))->toBeFalse("{$label} must be deleted");
    }

    // The attempts of a deleted delivery go with it.
    expect(WebhookDeliveryAttempt::withoutGlobalScopes()->count())->toBe(0);
});

test('the retention periods come from the configuration, the endpoints are untouched, and a second run deletes nothing more', function (): void {
    config(['webhooks.retention' => ['succeeded_days' => 1, 'dead_days' => 2, 'test_days' => 1]]);
    $tenant = complianceTenant();
    $endpoint = webhookEndpointFor($tenant);
    $delivery = webhookPendingDelivery($endpoint, ['status' => 'succeeded']);
    DB::table('webhook_deliveries')->where('id', $delivery->id)->update(['updated_at' => now()->subDays(2)]);

    $this->artisan('webhooks:prune')->assertSuccessful();
    $this->artisan('webhooks:prune')->expectsOutputToContain('Deleted 0 succeeded, 0 dead and 0 test')->assertSuccessful();

    expect(WebhookDelivery::withoutGlobalScopes()->count())->toBe(0)->and(DB::table('webhook_endpoints')->count())->toBe(1);
});

test('the prune command is scheduled every day and the policy is part of the catalogue answer', function (): void {
    $events = collect(app(Schedule::class)->events())->map(fn ($event): string => (string) $event->command)->implode("\n");
    expect($events)->toContain('webhooks:prune')->toContain('webhooks:dispatch-overdue');

    $tenant = complianceTenant();
    actingAsMember($tenant);
    $this->getJson('/api/v1/integrations/webhook-events')->assertOk()->assertJsonPath('data.retention.succeeded_days', 30)->assertJsonPath('data.retention.dead_days', 90)->assertJsonPath('data.retention.test_days', 7);
});

// ------------------------------------------------------------------------------------------- outbox row counting

test('no test or monitor counts the rows of the transactional outbox without filtering by event or aggregate', function (): void {
    // The webhook observers (invoice paid, contact created) add outbox rows for events that did not exist before, so a
    // check that counts all the rows would change. Every count in the code must be filtered.
    $finder = (new Finder)->files()->name('*.php')->in([base_path('tests'), base_path('app'), base_path('modules'), base_path('packages')])
        ->notPath('vendor')->notName('WebhookRetentionAndOutboxTest.php');

    $offenders = [];

    foreach ($finder as $file) {
        $source = $file->getContents();

        if (! str_contains($source, 'transactional_outbox') && ! str_contains($source, 'TransactionalOutbox')) {
            continue;
        }

        foreach (preg_split('/;\s*\n/', $source) ?: [] as $statement) {
            if (preg_match("/(transactional_outbox'\)|TransactionalOutbox::(query\(\)|withoutGlobalScopes\(\))?(->)?)\s*->?\s*(count|sum|exists|doesntExist)\(\)/", preg_replace('/\s+/', ' ', $statement) ?? '')
                && ! preg_match('/where|find|first|sole|pluck/', $statement)) {
                $offenders[] = $file->getRelativePathname();
            }

            if (preg_match('/assertDatabaseCount\(\s*.transactional_outbox/', $statement)) {
                $offenders[] = $file->getRelativePathname();
            }
        }
    }

    expect(array_values(array_unique($offenders)))->toBe([]);
});

test('the rows the new observers add to the outbox are relayed without failure and do not disturb other events', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);
    $tenant = complianceTenant();
    openPeriod($tenant);
    actingAsMember($tenant);
    $invoice = webhookOutboxTestInvoice($tenant);

    Contact::create(['type' => 'customer', 'company_name' => 'Cliente nuovo']);
    $this->postJson("/api/v1/sales/invoices/{$invoice->id}/payments", ['amount' => 122, 'paid_at' => '2027-01-20'])->assertSuccessful();

    $types = TransactionalOutbox::query()->whereIn('event_type', ['crm.contact.created', 'invoicing.invoice.paid'])->pluck('event_type')->sort()->values()->all();
    expect($types)->toContain('crm.contact.created', 'invoicing.invoice.paid');

    (new RelayOutboxEventsJob)->handle();
    (new RelayOutboxEventsJob)->handle();

    expect(TransactionalOutbox::query()->where('status', TransactionalOutbox::STATUS_PENDING)->count())->toBe(0)
        ->and(TransactionalOutbox::query()->where('status', TransactionalOutbox::STATUS_FAILED)->count())->toBe(0)
        ->and(TransactionalOutbox::query()->whereIn('event_type', ['crm.contact.created', 'invoicing.invoice.paid'])->whereIn('status', TransactionalOutbox::DISPATCHED_STATUSES)->count())->toBe(count($types));
});

function webhookOutboxTestInvoice(Tenant $tenant): Invoice
{
    $contact = Contact::create(['tenant_id' => $tenant->id, 'type' => 'customer', 'company_name' => 'Cliente']);
    $invoice = new Invoice([
        'contact_id' => $contact->id, 'invoice_type' => 'standard', 'invoice_number' => 'FT-OB-'.Str::random(4), 'date' => '2027-01-15', 'due_date' => '2027-02-15',
        'currency' => 'EUR', 'total_net' => 100, 'total_tax' => 22, 'total_gross' => 122, 'total_paid' => 0, 'payment_status' => 'unpaid',
    ]);
    $invoice->tenant_id = $tenant->id;
    $invoice->save();

    return $invoice;
}
