<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;
use Modules\Integrations\Models\WebhookEndpoint;
use Modules\Integrations\Webhooks\WebhookEventIngestor;
use Modules\Invoicing\Events\InvoicePostedEvent;
use Modules\Treasury\Events\CollectionReceivedEvent;

require_once __DIR__.'/../Compliance/ComplianceHelpers.php';

/**
 * An active endpoint of the tenant; its signing secret is readable as $endpoint->secret (the encrypted cast decrypts it).
 *
 * @param  list<string>  $events
 * @param  array<string, mixed>  $attributes
 */
function webhookEndpointFor(Tenant $tenant, array $events = ['invoicing.invoice.posted'], array $attributes = []): WebhookEndpoint
{
    $secret = WebhookEndpoint::SecretPrefix.Str::random(40);

    return app(TenantContext::class)->runWithTenant($tenant, fn (): WebhookEndpoint => WebhookEndpoint::factory()->create([
        'tenant_id' => $tenant->id,
        'events' => $events,
        'secret' => $secret,
        'secret_hint' => substr($secret, -4),
        ...$attributes,
    ]));
}

function invoicePostedEventFor(string $tenantId, string $invoiceNumber = 'FT-1'): InvoicePostedEvent
{
    return new InvoicePostedEvent(
        invoiceId: (string) Str::uuid7(),
        invoiceNumber: $invoiceNumber,
        customerId: (string) Str::uuid7(),
        date: '2027-01-15',
        dueDate: '2027-02-15',
        currency: 'EUR',
        exchangeRate: 1.0,
        totalNet: 100.0,
        totalTax: 22.0,
        totalGross: 122.0,
        revenueLines: [],
        taxLines: [],
        glJournalEntryId: null,
        tenantId: $tenantId,
        companyId: $tenantId,
    );
}

function collectionReceivedEventFor(string $tenantId): CollectionReceivedEvent
{
    return new CollectionReceivedEvent(
        collectionId: (string) Str::uuid7(),
        customerId: (string) Str::uuid7(),
        invoiceId: (string) Str::uuid7(),
        collectedAmount: 122.0,
        collectionDate: '2027-01-20',
        tenantId: $tenantId,
        companyId: $tenantId,
    );
}

/**
 * Hands the event to the webhook ingestor, as its in-process listener does, without running the other listeners of the
 * event (an invoice posting would also post to the ledger).
 */
function ingestWebhookEvent(DomainEventInterface $event): int
{
    return app(WebhookEventIngestor::class)->fromEvent($event);
}
