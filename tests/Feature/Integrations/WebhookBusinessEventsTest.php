<?php

declare(strict_types=1);

use App\Actions\Sales\RecordPayment;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Enterprise\EventBus\Models\TransactionalOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Integrations\Models\WebhookDelivery;
use Modules\Inventory\Contracts\DoubleEntryMovementServiceInterface;
use Modules\Inventory\Contracts\InventoryReplenishmentServiceInterface;
use Modules\Inventory\DTOs\StockTransferDTO;
use Modules\Inventory\Jobs\EvaluateWarehouseReorderRulesJob;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\StockQuant;
use Modules\Inventory\Models\StockReorderRule;
use Modules\Inventory\Models\WarehouseBin;
use Modules\Invoicing\Models\SalesInvoice;

require_once __DIR__.'/WebhookHelpers.php';
require_once __DIR__.'/../Inventory/Wms/wms_fixtures.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response('ok', 200)]);
});

/**
 * @return list<WebhookDelivery>
 */
function webhookDeliveriesOf(string $eventName, ?string $tenantId = null): array
{
    return WebhookDelivery::withoutGlobalScopes()->where('event_name', $eventName)
        ->when($tenantId, fn ($query) => $query->where('tenant_id', $tenantId))->orderBy('created_at')->get()->all();
}

function webhookTestInvoice(Tenant $tenant, array $attributes = []): Invoice
{
    $contact = Contact::create(['tenant_id' => $tenant->id, 'type' => 'customer', 'company_name' => 'Cliente']);
    $invoice = new Invoice([
        'contact_id' => $contact->id, 'invoice_type' => 'standard', 'invoice_number' => 'FT-'.Str::random(4), 'date' => '2027-01-15', 'due_date' => '2027-02-15',
        'currency' => 'EUR', 'total_net' => 100, 'total_tax' => 22, 'total_gross' => 122, 'total_paid' => 0, 'payment_status' => 'unpaid', ...$attributes,
    ]);
    $invoice->tenant_id = $tenant->id;
    $invoice->save();

    return $invoice;
}

// ---------------------------------------------------------------------------------------------------- invoice paid

describe('invoicing.invoice.paid', function (): void {
    beforeEach(function (): void {
        $this->tenant = complianceTenant('Alfa');
        $this->other = complianceTenant('Beta');
        openPeriod($this->tenant);
        actingAsMember($this->tenant);
        $this->endpoint = webhookEndpointFor($this->tenant, ['invoicing.invoice.paid'], ['url' => 'https://alfa.example.com/hook']);
        $this->otherEndpoint = webhookEndpointFor($this->other, ['invoicing.invoice.paid'], ['url' => 'https://beta.example.com/hook']);
    });

    test('it fires once when payments settle the invoice, not on the partial payment, not on later saves', function (): void {
        $invoice = webhookTestInvoice($this->tenant);

        $this->postJson("/api/v1/sales/invoices/{$invoice->id}/payments", ['amount' => 50, 'paid_at' => '2027-01-20'])->assertSuccessful();
        expect(webhookDeliveriesOf('invoicing.invoice.paid'))->toHaveCount(0)->and($invoice->refresh()->payment_status)->toBe('partially_paid');

        $this->postJson("/api/v1/sales/invoices/{$invoice->id}/payments", ['amount' => 72, 'paid_at' => '2027-01-25'])->assertSuccessful();

        $deliveries = webhookDeliveriesOf('invoicing.invoice.paid');
        expect($deliveries)->toHaveCount(1)->and($invoice->refresh()->payment_status)->toBe('paid');

        $data = $deliveries[0]->payload['data'];
        expect($deliveries[0]->tenant_id)->toBe($this->tenant->id)->and($deliveries[0]->payload['type'])->toBe('invoicing.invoice.paid')
            ->and($data['invoice_id'])->toBe($invoice->id)->and($data['invoice_number'])->toBe($invoice->invoice_number)
            ->and($data['customer_id'])->toBe($invoice->contact_id)->and($data['currency'])->toBe('EUR')
            ->and($data['total_gross'])->toEqual(122)->and($data['total_paid'])->toEqual(122)->and($data['paid_at'])->toBeString()
            ->and($data)->not->toHaveKeys(['tenant_id', 'company_id']);

        // Saving the paid invoice again, or recalculating it, is not a transition.
        $invoice->update(['notes' => 'x']);
        $invoice->refresh()->save();
        app(RecordPayment::class)->recalculate($invoice);

        expect(webhookDeliveriesOf('invoicing.invoice.paid'))->toHaveCount(1);
        Http::assertSentCount(1);
    });

    test('a payment settling the whole invoice at once fires once, and the flow of payments is unchanged', function (): void {
        $invoice = webhookTestInvoice($this->tenant);

        $this->postJson("/api/v1/sales/invoices/{$invoice->id}/payments", ['amount' => 122, 'paid_at' => '2027-01-20'])->assertSuccessful();

        expect(webhookDeliveriesOf('invoicing.invoice.paid'))->toHaveCount(1)
            ->and($invoice->refresh()->total_paid)->toEqual(122)
            ->and($invoice->payments()->count())->toBe(1)
            ->and(TransactionalOutbox::query()->where('event_type', 'invoicing.invoice.paid')->count())->toBe(1);

        // An overpayment is still refused, nothing is raised.
        $this->postJson("/api/v1/sales/invoices/{$invoice->id}/payments", ['amount' => 1, 'paid_at' => '2027-01-21'])->assertUnprocessable();
        expect(webhookDeliveriesOf('invoicing.invoice.paid'))->toHaveCount(1);
    });

    test('it fires again only after the invoice went back to partially paid and is settled anew', function (): void {
        $invoice = webhookTestInvoice($this->tenant);
        $this->postJson("/api/v1/sales/invoices/{$invoice->id}/payments", ['amount' => 122, 'paid_at' => '2027-01-20'])->assertSuccessful();

        $payment = $invoice->payments()->first();
        $payment->update(['amount' => 100]);
        app(RecordPayment::class)->recalculate($invoice);
        expect($invoice->refresh()->payment_status)->toBe('partially_paid')->and(webhookDeliveriesOf('invoicing.invoice.paid'))->toHaveCount(1);

        $this->postJson("/api/v1/sales/invoices/{$invoice->id}/payments", ['amount' => 22, 'paid_at' => '2027-01-22'])->assertSuccessful();

        expect(webhookDeliveriesOf('invoicing.invoice.paid'))->toHaveCount(2);
    });

    test('settlement by a credit note, a bank reconciliation or any other save that marks it paid fires too', function (): void {
        $byOtherPath = webhookTestInvoice($this->tenant);
        $byOtherPath->payment_status = 'paid';
        $byOtherPath->total_paid = 122;
        $byOtherPath->save();

        $sales = SalesInvoice::withoutGlobalScopes()->findOrFail(webhookTestInvoice($this->tenant)->id);
        $sales->payment_status = 'paid';
        $sales->save();

        expect(webhookDeliveriesOf('invoicing.invoice.paid'))->toHaveCount(2);
    });

    test('proforma invoices, credit notes and invoices created already paid raise nothing', function (): void {
        $proforma = webhookTestInvoice($this->tenant, ['invoice_type' => 'proforma']);
        $proforma->update(['payment_status' => 'paid']);
        $creditNote = webhookTestInvoice($this->tenant, ['invoice_type' => 'credit_note']);
        $creditNote->update(['payment_status' => 'paid']);
        webhookTestInvoice($this->tenant, ['payment_status' => 'paid']);

        expect(webhookDeliveriesOf('invoicing.invoice.paid'))->toHaveCount(0);
        Http::assertNothingSent();
    });

    test('only the endpoints of the tenant of the invoice receive it', function (): void {
        $invoice = webhookTestInvoice($this->tenant);
        $invoice->update(['payment_status' => 'paid', 'total_paid' => 122]);

        $foreign = webhookTestInvoice($this->other);
        $foreign->update(['payment_status' => 'paid', 'total_paid' => 122]);

        expect(webhookDeliveriesOf('invoicing.invoice.paid', $this->tenant->id))->toHaveCount(1)
            ->and(webhookDeliveriesOf('invoicing.invoice.paid', $this->other->id))->toHaveCount(1);

        $urls = collect(Http::recorded())->map(fn (array $pair): string => $pair[0]->url())->sort()->values()->all();
        expect($urls)->toBe(['https://alfa.example.com/hook', 'https://beta.example.com/hook']);

        foreach (webhookDeliveriesOf('invoicing.invoice.paid') as $delivery) {
            expect($delivery->payload['tenant_id'])->toBe($delivery->tenant_id)
                ->and($delivery->payload['data']['invoice_id'])->toBe($delivery->tenant_id === $this->tenant->id ? $invoice->id : $foreign->id);
        }
    });
});

// ------------------------------------------------------------------------------------------------ contact created

describe('crm.contact.created', function (): void {
    beforeEach(function (): void {
        $this->tenant = complianceTenant('Alfa');
        $this->other = complianceTenant('Beta');
        actingAsMember($this->tenant);
        webhookEndpointFor($this->tenant, ['crm.contact.created'], ['url' => 'https://alfa.example.com/hook']);
        webhookEndpointFor($this->other, ['crm.contact.created'], ['url' => 'https://beta.example.com/hook']);
    });

    test('creating a contact through the API fires once, with the documented payload, and the API is unchanged', function (): void {
        $created = $this->postJson('/api/v1/crm/contacts', ['type' => 'customer', 'company_name' => 'ACME Srl', 'email' => 'info@acme.example.com', 'vat_number' => '00811720580', 'country' => 'IT'])
            ->assertCreated()->json('data');

        $deliveries = webhookDeliveriesOf('crm.contact.created');

        expect($deliveries)->toHaveCount(1)->and($deliveries[0]->tenant_id)->toBe($this->tenant->id)
            ->and($deliveries[0]->payload['data'])->toEqual([
                'contact_id' => $created['id'], 'contact_type' => 'customer', 'company_name' => 'ACME Srl', 'first_name' => null, 'last_name' => null,
                'email' => 'info@acme.example.com', 'country' => 'IT', 'vat_number' => '00811720580',
            ])
            ->and(AuditLedgerEntry::query()->where('resource_id', $created['id'])->where('action', 'CREATED')->count())->toBe(1)
            ->and(TransactionalOutbox::query()->where('event_type', 'crm.contact.created')->where('aggregate_id', $created['id'])->count())->toBe(1);
    });

    test('it does not fire again on update, delete or restore', function (): void {
        $contact = Contact::create(['type' => 'supplier', 'first_name' => 'Mario', 'last_name' => 'Rossi']);
        $contact->update(['email' => 'mario@example.com']);
        $contact->delete();
        $contact->restore();

        expect(webhookDeliveriesOf('crm.contact.created'))->toHaveCount(1);
    });

    test('every creation path fires once: model, factory, firstOrCreate', function (): void {
        Contact::create(['type' => 'customer', 'company_name' => 'A']);
        Contact::factory()->create(['tenant_id' => $this->tenant->id]);
        Contact::firstOrCreate(['vat_number' => '12345678901'], ['type' => 'customer', 'company_name' => 'B']);
        Contact::firstOrCreate(['vat_number' => '12345678901'], ['type' => 'customer', 'company_name' => 'B']);

        expect(webhookDeliveriesOf('crm.contact.created'))->toHaveCount(3);
    });

    test('a contact reaches only the endpoints of its tenant', function (): void {
        Contact::create(['type' => 'customer', 'company_name' => 'Alfa customer']);
        app(TenantContext::class)->runWithTenant($this->other, fn () => Contact::create(['type' => 'customer', 'company_name' => 'Beta customer']));

        $alfa = webhookDeliveriesOf('crm.contact.created', $this->tenant->id);
        $beta = webhookDeliveriesOf('crm.contact.created', $this->other->id);

        expect($alfa)->toHaveCount(1)->and($alfa[0]->payload['data']['company_name'])->toBe('Alfa customer')
            ->and($beta)->toHaveCount(1)->and($beta[0]->payload['data']['company_name'])->toBe('Beta customer');

        $sent = collect(Http::recorded())->map(fn (array $pair): array => [$pair[0]->url(), json_decode($pair[0]->body(), true)['data']['company_name']])->sortBy(0)->values()->all();
        expect($sent)->toBe([['https://alfa.example.com/hook', 'Alfa customer'], ['https://beta.example.com/hook', 'Beta customer']]);
    });
});

// ------------------------------------------------------------------------------------------- stock below minimum

describe('inventory.stock_below_minimum', function (): void {
    beforeEach(function (): void {
        $this->tenant = logisticsTenant('Alfa Logistica');
        logisticsUser($this->tenant);
        $this->warehouse = logisticsWarehouse();
        $this->bin = logisticsBin($this->warehouse);
        $this->item = logisticsItem(['tracking_type' => 'none']);
        $this->rule = StockReorderRule::factory()->create([
            'tenant_id' => $this->tenant->id, 'warehouse_id' => $this->warehouse->id, 'stock_item_id' => $this->item->id,
            'min_stock_level' => 5, 'reorder_point' => 8, 'max_stock_level' => 40, 'economic_order_quantity' => 20,
        ]);
        $this->endpoint = webhookEndpointFor($this->tenant, ['inventory.stock_below_minimum'], ['url' => 'https://alfa.example.com/hook']);
    });

    function webhookIssueStock(object $test, string $quantity): void
    {
        app(DoubleEntryMovementServiceInterface::class)->executeTransfer(new StockTransferDTO(
            stockItemId: (string) $test->item->id, sourceBinId: (string) $test->bin->id, destinationBinId: null, quantity: (float) $quantity, movementType: 'customer_delivery',
        ));
    }

    test('it fires on the crossing only, not on every movement below the minimum, and fires again after a recovery', function (): void {
        wmsReceive($this->bin, $this->item, '20');
        webhookIssueStock($this, '10');
        expect(webhookDeliveriesOf('inventory.stock_below_minimum'))->toHaveCount(0);

        webhookIssueStock($this, '6');

        $deliveries = webhookDeliveriesOf('inventory.stock_below_minimum');
        expect($deliveries)->toHaveCount(1)->and($deliveries[0]->payload['data'])->toEqual([
            'scope' => 'warehouse', 'reorder_rule_id' => $this->rule->id, 'stock_item_id' => $this->item->id, 'warehouse_id' => $this->warehouse->id, 'product_id' => null, 'available_stock' => 4, 'minimum_stock' => 5,
        ])->and($this->rule->refresh()->below_minimum_since)->not->toBeNull();

        // Still below: more issues and the hourly evaluation announce nothing.
        webhookIssueStock($this, '1');
        webhookIssueStock($this, '1');
        app(InventoryReplenishmentServiceInterface::class)->evaluateMinimumStock($this->rule->refresh());
        (new EvaluateWarehouseReorderRulesJob($this->tenant->id))->handle(app(InventoryReplenishmentServiceInterface::class));
        expect(webhookDeliveriesOf('inventory.stock_below_minimum'))->toHaveCount(1);

        // Back to the minimum: no event, the state is cleared; the next drop is a new crossing.
        wmsReceive($this->bin, $this->item, '10');
        expect(webhookDeliveriesOf('inventory.stock_below_minimum'))->toHaveCount(1)->and($this->rule->refresh()->below_minimum_since)->toBeNull();

        webhookIssueStock($this, '8');
        expect(webhookDeliveriesOf('inventory.stock_below_minimum'))->toHaveCount(2);
        Http::assertSentCount(2);
    });

    test('exactly the minimum is not below it', function (): void {
        wmsReceive($this->bin, $this->item, '10');
        webhookIssueStock($this, '5');

        expect(webhookDeliveriesOf('inventory.stock_below_minimum'))->toHaveCount(0);

        webhookIssueStock($this, '0.5');

        expect(webhookDeliveriesOf('inventory.stock_below_minimum'))->toHaveCount(1);
    });

    test('a reservation that lowers the available stock below the minimum counts', function (): void {
        wmsReceive($this->bin, $this->item, '10');
        $quant = StockQuant::query()->where('stock_item_id', $this->item->id)->firstOrFail();
        $quant->forceFill(['quantity_reserved' => 6])->save();

        app(InventoryReplenishmentServiceInterface::class)->evaluateMinimumStock($this->rule->refresh());

        expect(webhookDeliveriesOf('inventory.stock_below_minimum'))->toHaveCount(1);
    });

    test('a rule without a minimum is not monitored, and the reorder point event is unchanged', function (): void {
        $this->rule->update(['min_stock_level' => 0]);
        wmsReceive($this->bin, $this->item, '20');
        webhookIssueStock($this, '19');

        expect(webhookDeliveriesOf('inventory.stock_below_minimum'))->toHaveCount(0);

        // The existing reorder point evaluation still announces its own event (stock 1 is at or below 8).
        $eventId = app(InventoryReplenishmentServiceInterface::class)->evaluateReorderRule($this->rule->refresh());

        expect($eventId)->not->toBeNull()->and(TransactionalOutbox::query()->where('event_type', 'inventory.reorder_point_reached')->count())->toBeGreaterThan(0);
    });

    test('stock movements are unchanged: quantities and the movement ledger are the same with the listener', function (): void {
        wmsReceive($this->bin, $this->item, '20');
        webhookIssueStock($this, '16');

        expect(wmsOnHand($this->bin, $this->item))->toBe('4.0000')
            ->and(StockMovement::query()->where('stock_item_id', $this->item->id)->count())->toBe(2);
    });

    test('the event of a tenant never reaches the endpoints of another tenant', function (): void {
        $other = Tenant::create(['name' => 'Beta', 'legal_name' => 'Beta SA', 'country' => 'IT', 'default_currency' => 'EUR']);
        $otherEndpoint = webhookEndpointFor($other, ['inventory.stock_below_minimum'], ['url' => 'https://beta.example.com/hook']);

        wmsReceive($this->bin, $this->item, '20');
        webhookIssueStock($this, '18');

        expect(webhookDeliveriesOf('inventory.stock_below_minimum', $this->tenant->id))->toHaveCount(1)
            ->and(webhookDeliveriesOf('inventory.stock_below_minimum', $other->id))->toHaveCount(0)
            ->and(WebhookDelivery::withoutGlobalScopes()->where('webhook_endpoint_id', $otherEndpoint->id)->count())->toBe(0);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://alfa.example.com/hook' && json_decode($request->body(), true)['tenant_id'] === $this->tenant->id);
        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://beta.example.com/hook');
    });
});

// ------------------------------------------------------------------------ product minimum (products.min_stock)

describe('inventory.stock_below_minimum, scope product', function (): void {
    beforeEach(function (): void {
        $this->tenant = logisticsTenant('Alfa Logistica');
        logisticsUser($this->tenant);
        $this->warehouse = logisticsWarehouse();
        $this->bin = logisticsBin($this->warehouse);
        ['product' => $this->product, 'item' => $this->item] = wmsProduct('SKU-MIN-'.Str::random(4));
        $this->product->update(['min_stock' => 5]);
        $this->endpoint = webhookEndpointFor($this->tenant, ['inventory.stock_below_minimum'], ['url' => 'https://alfa.example.com/hook']);
    });

    function webhookIssueProduct(object $test, WarehouseBin $bin, string $quantity): void
    {
        app(DoubleEntryMovementServiceInterface::class)->executeTransfer(new StockTransferDTO(
            stockItemId: (string) $test->item->id, sourceBinId: (string) $bin->id, destinationBinId: null, quantity: (float) $quantity, movementType: 'customer_delivery',
        ));
    }

    test('it fires on the crossing of the product minimum only, with the product payload, and again after a recovery', function (): void {
        wmsReceive($this->bin, $this->item, '20');
        webhookIssueProduct($this, $this->bin, '10');
        expect(webhookDeliveriesOf('inventory.stock_below_minimum'))->toHaveCount(0);

        webhookIssueProduct($this, $this->bin, '6');

        $deliveries = webhookDeliveriesOf('inventory.stock_below_minimum');
        expect($deliveries)->toHaveCount(1)->and($deliveries[0]->payload['data'])->toEqual([
            'scope' => 'product', 'reorder_rule_id' => null, 'stock_item_id' => null, 'warehouse_id' => null, 'product_id' => $this->product->id, 'available_stock' => 4, 'minimum_stock' => 5,
        ])->and($this->product->refresh()->below_minimum_since)->not->toBeNull();

        webhookIssueProduct($this, $this->bin, '1');
        app(InventoryReplenishmentServiceInterface::class)->evaluateProductMinimumStock($this->product->refresh());
        expect(webhookDeliveriesOf('inventory.stock_below_minimum'))->toHaveCount(1);

        wmsReceive($this->bin, $this->item, '10');
        expect(webhookDeliveriesOf('inventory.stock_below_minimum'))->toHaveCount(1)->and($this->product->refresh()->below_minimum_since)->toBeNull();

        webhookIssueProduct($this, $this->bin, '9');
        expect(webhookDeliveriesOf('inventory.stock_below_minimum'))->toHaveCount(2);
    });

    test('the stock counted is the product total across warehouses', function (): void {
        $second = logisticsWarehouse();
        $secondBin = logisticsBin($second, bin: 'B2');
        wmsReceive($this->bin, $this->item, '6');
        wmsReceive($secondBin, $this->item, '6');
        expect(webhookDeliveriesOf('inventory.stock_below_minimum'))->toHaveCount(0);

        webhookIssueProduct($this, $secondBin, '6');
        expect(webhookDeliveriesOf('inventory.stock_below_minimum'))->toHaveCount(0);

        webhookIssueProduct($this, $this->bin, '2');

        $deliveries = webhookDeliveriesOf('inventory.stock_below_minimum');
        expect($deliveries)->toHaveCount(1)->and($deliveries[0]->payload['data']['available_stock'])->toEqual(4)->and($deliveries[0]->payload['data']['warehouse_id'])->toBeNull();
    });

    test('a product that is not monitored announces nothing and clears its state', function (): void {
        wmsReceive($this->bin, $this->item, '20');
        webhookIssueProduct($this, $this->bin, '18');
        expect(webhookDeliveriesOf('inventory.stock_below_minimum'))->toHaveCount(1);

        $this->product->update(['min_stock' => null]);
        webhookIssueProduct($this, $this->bin, '1');
        expect($this->product->refresh()->below_minimum_since)->toBeNull();

        $this->product->update(['min_stock' => 50, 'track_inventory' => false]);
        webhookIssueProduct($this, $this->bin, '0.5');
        $this->product->update(['min_stock' => 50, 'track_inventory' => true, 'is_active' => false]);
        webhookIssueProduct($this, $this->bin, '0.25');

        expect(webhookDeliveriesOf('inventory.stock_below_minimum'))->toHaveCount(1);
    });

    test('when the rule minimum of a warehouse and the product minimum both exist, each crossing announces its own scope', function (): void {
        StockReorderRule::factory()->create([
            'tenant_id' => $this->tenant->id, 'warehouse_id' => $this->warehouse->id, 'stock_item_id' => $this->item->id,
            'min_stock_level' => 5, 'reorder_point' => 8, 'max_stock_level' => 40, 'economic_order_quantity' => 20,
        ]);
        wmsReceive($this->bin, $this->item, '20');

        webhookIssueProduct($this, $this->bin, '16');

        $all = webhookDeliveriesOf('inventory.stock_below_minimum');
        $scopes = collect($all)->map(fn (WebhookDelivery $delivery): string => $delivery->payload['data']['scope'])->sort()->values()->all();
        $warehouse = collect($all)->first(fn (WebhookDelivery $d): bool => $d->payload['data']['scope'] === 'warehouse');

        expect($scopes)->toBe(['product', 'warehouse'])->and($warehouse->payload['data']['product_id'])->toBe($this->product->id);

        // Neither repeats while the stock stays below.
        webhookIssueProduct($this, $this->bin, '1');
        expect(webhookDeliveriesOf('inventory.stock_below_minimum'))->toHaveCount(2);
    });

    test('the hourly job announces a product whose minimum was raised above its stock, once', function (): void {
        $this->product->update(['min_stock' => null]);
        wmsReceive($this->bin, $this->item, '3');
        $this->product->update(['min_stock' => 5]);

        $job = new EvaluateWarehouseReorderRulesJob($this->tenant->id);
        $job->handle(app(InventoryReplenishmentServiceInterface::class));
        $job->handle(app(InventoryReplenishmentServiceInterface::class));

        expect(webhookDeliveriesOf('inventory.stock_below_minimum'))->toHaveCount(1);
    });

    test('the product minimum of a tenant never reaches the endpoints of another tenant', function (): void {
        $other = Tenant::create(['name' => 'Beta', 'legal_name' => 'Beta SA', 'country' => 'IT', 'default_currency' => 'EUR']);
        $otherEndpoint = webhookEndpointFor($other, ['inventory.stock_below_minimum'], ['url' => 'https://beta.example.com/hook']);

        wmsReceive($this->bin, $this->item, '20');
        webhookIssueProduct($this, $this->bin, '18');

        expect(webhookDeliveriesOf('inventory.stock_below_minimum', $this->tenant->id))->toHaveCount(1)
            ->and(WebhookDelivery::withoutGlobalScopes()->where('webhook_endpoint_id', $otherEndpoint->id)->count())->toBe(0);
        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://beta.example.com/hook');
    });
});
