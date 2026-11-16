<?php

declare(strict_types=1);

use App\Contracts\PdfGenerator;
use App\Models\DeliveryNote as LegacyDeliveryNote;
use App\Models\DeliveryNoteItem as LegacyDeliveryNoteItem;
use App\Services\Pdf\DocumentPdfService;
use App\Services\SwissQrBill\SwissQrBillService;
use App\Services\Tenancy\TenantContext;
use Database\Seeders\LogisticsTranslationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Inventory\Models\StockMovement;
use Modules\Invoicing\Models\SalesDeliveryNote;
use Modules\Invoicing\Models\SalesDeliveryNoteItem;
use Modules\Logistics\Contracts\ShippingCarrierDriverInterface;
use Modules\Logistics\Services\DeliveryNoteService;
use Modules\Logistics\Services\OrderBillingService;
use Modules\Logistics\Services\ShippingCarrierManager;
use Modules\Logistics\Services\WarehouseStockService;
use Modules\Logistics\Support\LogisticsPermissions;
use Modules\Logistics\Support\ShipmentResult;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    ['tenant' => $this->tenant, 'user' => $this->user] = logisticsTenant('Lifecycle');
    app(TenantContext::class)->setTenant($this->tenant);
    $this->customer = logisticsCustomer($this->tenant->id);
    $this->product = logisticsProduct($this->tenant->id, 'LC-1', 10, 50);
    $this->warehouse = logisticsWarehouse($this->tenant->id, 'MAIN');
    $this->stock = app(WarehouseStockService::class);
    $this->stock->receiveOpeningStock($this->warehouse->id, $this->product->id, 100);
    $this->ddt = app(DeliveryNoteService::class);
});

function lifecycleDraft(object $t, array $extra = [], float $qty = 10): SalesDeliveryNote
{
    return $t->ddt->create(array_merge([
        'type' => 'outbound_sale', 'contact_id' => $t->customer->id, 'origin_warehouse_id' => $t->warehouse->id,
    ], $extra), [['product_id' => $t->product->id, 'description' => 'Widget', 'quantity' => $qty, 'unit_price' => 50, 'tax_rate' => 22]]);
}

test('the legacy DeliveryNote is an alias of the canonical model on the same table', function (): void {
    expect(is_subclass_of(LegacyDeliveryNote::class, SalesDeliveryNote::class))->toBeTrue()
        ->and(is_subclass_of(LegacyDeliveryNoteItem::class, SalesDeliveryNoteItem::class))->toBeTrue()
        ->and((new LegacyDeliveryNote)->getTable())->toBe((new SalesDeliveryNote)->getTable());

    $note = lifecycleDraft($this);
    $legacy = LegacyDeliveryNote::query()->findOrFail($note->id);

    expect($legacy->ddt_number)->toBe($note->ddt_number)
        ->and($legacy->items)->toHaveCount(1)
        ->and($legacy->contact->id)->toBe($this->customer->id)
        ->and(SalesDeliveryNote::query()->count())->toBe(LegacyDeliveryNote::query()->count());

    // A legacy write is visible through the canonical model (no duplicated storage or logic)
    $legacy->update(['carrier_name' => 'Legacy Carrier']);
    expect($note->fresh()->carrier_name)->toBe('Legacy Carrier');
});

test('audit fields are populated from the authenticated user and soft deletes are recorded', function (): void {
    $this->actingAs($this->user);
    $note = lifecycleDraft($this);

    expect($note->created_by)->toBe($this->user->id)
        ->and($note->updated_by)->toBe($this->user->id)
        ->and($note->items->first()->created_by)->toBe($this->user->id);

    $this->ddt->delete($note);
    $trashed = SalesDeliveryNote::withTrashed()->findOrFail($note->id);
    expect($trashed->trashed())->toBeTrue()->and($trashed->deleted_by)->toBe($this->user->id);
});

test('status machine runs draft -> issued -> in_transit -> delivered keeping shipping_status in sync', function (): void {
    $note = lifecycleDraft($this);
    expect($note->status)->toBe('draft')->and($note->shipping_status)->toBe('draft');

    expect(fn () => $this->ddt->startTransit($note))->toThrow(DomainException::class); // not issued yet

    $note = $this->ddt->issue($note);
    expect($note->status)->toBe('issued')->and($note->shipping_status)->toBe('ready')->and($note->issued_at)->not->toBeNull();
    expect(fn () => $this->ddt->issue($note))->toThrow(DomainException::class);

    $note = $this->ddt->startTransit($note);
    expect($note->status)->toBe('in_transit')->and($note->shipping_status)->toBe('dispatched')->and($note->shipped_at)->not->toBeNull();

    $note = $this->ddt->markDelivered($note, 'Mario Rossi');
    expect($note->status)->toBe('delivered')->and($note->shipping_status)->toBe('delivered')->and($note->delivered_at)->not->toBeNull();
    expect(fn () => $this->ddt->cancel($note))->toThrow(DomainException::class);
});

test('issuing a sale DDT releases stock and cancelling it emits INBOUND_REVERSAL movements', function (): void {
    $note = $this->ddt->issue(lifecycleDraft($this, [], 10));
    expect($this->stock->onHand($this->warehouse->id, $this->product->id))->toBe(90.0);

    $note = $this->ddt->startTransit($note);
    $cancelled = $this->ddt->cancel($note);

    expect($cancelled->status)->toBe('cancelled')
        ->and($cancelled->shipping_status)->toBe('cancelled')
        ->and($cancelled->cancelled_at)->not->toBeNull()
        ->and($this->stock->onHand($this->warehouse->id, $this->product->id))->toBe(100.0);

    $reversal = StockMovement::query()->where('movement_type', 'INBOUND_REVERSAL')->get();
    expect($reversal)->toHaveCount(1)
        ->and((float) $reversal->first()->quantity)->toBe(10.0)
        ->and($reversal->first()->warehouse_id)->toBe($this->warehouse->id)
        ->and($reversal->first()->origin_document_id)->toBe($note->id);
});

test('cancelling an order-fulfilling DDT rolls back the order line quantities and stock', function (): void {
    $order = logisticsOrder($this->tenant->id, $this->customer->id, $this->product->id, 10, 'SO-LC');
    $note = $this->ddt->createFromOrders([['sales_order_id' => $order->id]], ['origin_warehouse_id' => $this->warehouse->id]);
    $note = $this->ddt->issue($note);

    expect($order->fresh()->fulfillment_status)->toBe('fulfilled')
        ->and($this->stock->onHand($this->warehouse->id, $this->product->id))->toBe(90.0);

    $this->ddt->cancel($note);

    expect($order->fresh('items')->items->first()->quantity_delivered)->toEqual('0.0000')
        ->and($order->fresh()->fulfillment_status)->toBe('unfulfilled')
        ->and($this->stock->onHand($this->warehouse->id, $this->product->id))->toBe(100.0);
});

test('an invoiced DDT cannot be cancelled', function (): void {
    $note = $this->ddt->issue(lifecycleDraft($this));
    app(OrderBillingService::class)->issueDeferredInvoice([$note->id]);

    expect(fn () => $this->ddt->cancel($note->fresh()))->toThrow(DomainException::class);
});

test('draft DDTs can be edited and deleted, issued ones cannot', function (): void {
    $note = lifecycleDraft($this);
    $updated = $this->ddt->update($note, ['carrier_name' => 'BRT', 'packages_count' => 4], [['description' => 'New line', 'quantity' => 3, 'unit_price' => 5]]);

    expect($updated->carrier_name)->toBe('BRT')->and($updated->packages_count)->toBe(4)
        ->and($updated->items)->toHaveCount(1)->and($updated->items->first()->description)->toBe('New line');

    $issued = $this->ddt->issue(lifecycleDraft($this));
    expect(fn () => $this->ddt->update($issued, ['carrier_name' => 'X']))->toThrow(DomainException::class)
        ->and(fn () => $this->ddt->delete($issued))->toThrow(DomainException::class);

    $this->ddt->delete($note);
    expect(SalesDeliveryNote::query()->find($note->id))->toBeNull();
});

test('numbering restarts every fiscal year', function (): void {
    $this->travelTo('2026-12-30 10:00:00');
    $a = lifecycleDraft($this);
    $b = lifecycleDraft($this);
    $this->travelTo('2027-01-02 10:00:00');
    $c = lifecycleDraft($this);

    expect($a->ddt_number)->toBe('DDT-2026-00001')->and($b->ddt_number)->toBe('DDT-2026-00002')
        ->and($c->ddt_number)->toBe('DDT-2027-00001')->and($c->fiscal_year)->toBe(2027);
});

test('deferred invoicing run groups unbilled DDTs per customer and references each DDT number and date', function (): void {
    $this->travelTo('2026-05-10 09:00:00');
    $other = logisticsCustomer($this->tenant->id, 'Secondo Cliente');
    $a1 = $this->ddt->issue(lifecycleDraft($this));
    $a2 = $this->ddt->issue(lifecycleDraft($this));
    $b1 = $this->ddt->markDelivered($this->ddt->startTransit($this->ddt->issue(lifecycleDraft($this, ['contact_id' => $other->id]))));
    lifecycleDraft($this); // a draft must be ignored

    $invoices = app(OrderBillingService::class)->runDeferredInvoicing();

    expect($invoices)->toHaveCount(2);
    $forFirst = collect($invoices)->first(fn ($i) => $i->contact_id === $this->customer->id);
    expect($forFirst->items)->toHaveCount(2)
        ->and($forFirst->notes)->toContain("DDT n. {$a1->ddt_number} del 10/05/2026")->toContain("DDT n. {$a2->ddt_number} del 10/05/2026")
        ->and($forFirst->items->first()->description)->toContain($a1->ddt_number)->toContain('del 10/05/2026')
        ->and($a1->fresh()->status)->toBe('invoiced')->and($b1->fresh()->status)->toBe('invoiced');

    // Nothing left to bill
    expect(app(OrderBillingService::class)->runDeferredInvoicing())->toHaveCount(0)
        ->and(app(OrderBillingService::class)->unbilledByCustomer())->toBe([]);
});

test('RBAC: default role matrix grants the expected DDT permissions', function (): void {
    $matrix = LogisticsPermissions::roleMatrix();

    expect($matrix['owner'])->toContain('delivery_notes.cancel', 'delivery_notes.invoice', 'logistics.transfers.manage')
        ->and($matrix['warehouse_operator'])->toContain('delivery_notes.issue')->not->toContain('delivery_notes.cancel', 'delivery_notes.invoice')
        ->and($matrix['accountant'])->toContain('delivery_notes.invoice', 'delivery_notes.pdf')->not->toContain('delivery_notes.create', 'delivery_notes.issue')
        ->and($matrix['viewer'])->toBe(['delivery_notes.view', 'delivery_notes.pdf'])
        ->and($matrix['sales_manager'])->toContain('delivery_notes.cancel', 'delivery_notes.invoice');
});

test('RBAC: endpoints enforce permissions per tenant role, including the legacy API', function (): void {
    $note = lifecycleDraft($this);
    $headers = fn (string $tenantId) => ['X-Tenant-Id' => $tenantId];

    foreach ([
        ['viewer', 'GET', "/api/v1/logistics/delivery-notes/{$note->id}", 200],
        ['viewer', 'POST', '/api/v1/logistics/delivery-notes', 403],
        ['viewer', 'POST', "/api/v1/logistics/delivery-notes/{$note->id}/issue", 403],
        ['warehouse_operator', 'POST', "/api/v1/logistics/delivery-notes/{$note->id}/cancel", 403],
        ['warehouse_operator', 'POST', '/api/v1/logistics/delivery-notes/deferred-invoice', 403],
        ['accountant', 'POST', '/api/v1/logistics/delivery-notes', 403],
        ['accountant', 'GET', '/api/v1/logistics/delivery-notes/unbilled', 200],
        ['sales_manager', 'GET', "/api/v1/logistics/delivery-notes/{$note->id}/pdf", 200],
        ['viewer', 'GET', '/api/v1/sales/delivery-notes', 200],
        ['member', 'GET', '/api/v1/sales/delivery-notes', 403],
        ['member', 'GET', '/api/v1/invoicing/delivery-notes', 403],
    ] as [$role, $method, $url, $expected]) {
        $actor = logisticsUserWithRole($this->tenant->id, $role);
        Sanctum::actingAs($actor);
        $response = $this->json($method, $url, [], $headers($this->tenant->id));
        expect($response->status())->toBe($expected, "{$role} {$method} {$url} returned {$response->status()}");
    }
});

test('the profile exposes the effective permissions so the frontend can hide actions', function (): void {
    $viewer = logisticsUserWithRole($this->tenant->id, 'viewer');
    Sanctum::actingAs($viewer);

    $permissions = $this->getJson('/api/v1/auth/profile', ['X-Tenant-Id' => $this->tenant->id])->assertOk()->json('data.permissions');
    expect($permissions)->toBe(['delivery_notes.view', 'delivery_notes.pdf']);

    Sanctum::actingAs($this->user); // owner
    expect($this->getJson('/api/v1/auth/profile', ['X-Tenant-Id' => $this->tenant->id])->json('data.permissions'))->toContain('delivery_notes.cancel', 'delivery_notes.invoice');
});

test('cross-tenant access is rejected on every DDT action', function (): void {
    $note = lifecycleDraft($this);
    ['tenant' => $otherTenant, 'user' => $otherUser] = logisticsTenant('Intruder');
    Sanctum::actingAs($otherUser);
    $h = ['X-Tenant-Id' => $otherTenant->id];

    foreach (['issue', 'dispatch', 'deliver', 'cancel'] as $action) {
        $this->postJson("/api/v1/logistics/delivery-notes/{$note->id}/{$action}", [], $h)->assertNotFound();
    }
    $this->putJson("/api/v1/logistics/delivery-notes/{$note->id}", ['carrier_name' => 'x'], $h)->assertNotFound();
    $this->deleteJson("/api/v1/logistics/delivery-notes/{$note->id}", [], $h)->assertNotFound();
    $this->getJson("/api/v1/logistics/delivery-notes/{$note->id}/pdf", $h)->assertNotFound();
});

test('PDF renders in the requested language from DB translations, with three+ signature blocks and optional hidden prices', function (): void {
    $this->seed(LogisticsTranslationSeeder::class);
    $fake = new class implements PdfGenerator
    {
        /** @var list<array{view: string, data: array<string, mixed>}> */
        public array $calls = [];

        public function render(string $view, array $data = []): string
        {
            $this->calls[] = ['view' => $view, 'data' => $data];

            return '%PDF-fake';
        }
    };
    $service = new DocumentPdfService($fake, app(SwissQrBillService::class));

    $note = lifecycleDraft($this, ['show_prices' => true, 'departure_time' => '2026-03-03 08:30:00', 'carriage_terms' => 'free', 'transport_by' => 'sender', 'incoterms' => 'DAP']);
    $legacy = LegacyDeliveryNote::query()->findOrFail($note->id);

    $htmlFor = function (array $options) use ($service, $fake, $legacy): string {
        $service->render($legacy, $options);
        $call = $fake->calls[array_key_last($fake->calls)];
        app()->setLocale($options['locale'] ?? 'en');

        return view($call['view'], $call['data'])->render();
    };

    $it = $htmlFor(['locale' => 'it']);
    expect($it)->toContain('Dati del trasporto')->toContain('Firma mittente')->toContain('Firma conducente')->toContain('Firma vettore')
        ->toContain('Franco')->toContain('Mittente')->toContain('2026-03-03 08:30');

    $de = $htmlFor(['locale' => 'de']);
    expect($de)->toContain('Transportdaten')->toContain('Unterschrift Frachtführer');

    $fr = $htmlFor(['locale' => 'fr']);
    expect($fr)->toContain('Données du transport')->toContain('Signature du transporteur');

    $en = $htmlFor(['locale' => 'en']);
    expect($en)->toContain('Transport details')->toContain('Sender signature')->toContain('Net total');

    $hidden = $htmlFor(['locale' => 'en', 'hide_prices' => true]);
    expect($hidden)->not->toContain('Net total')->and($fake->calls[array_key_last($fake->calls)]['data']['show_prices'])->toBeFalse();
});

test('carrier driver hooks (#158): custom drivers book shipments on dispatch and are told about cancellations', function (): void {
    $driver = new class implements ShippingCarrierDriverInterface
    {
        public bool $cancelled = false;

        public function code(): string
        {
            return 'acme';
        }

        public function supports(SalesDeliveryNote $note): bool
        {
            return $note->carrier_name === 'ACME Express';
        }

        public function trackingUrl(SalesDeliveryNote $note): ?string
        {
            return 'https://track.acme.test/'.$note->tracking_number;
        }

        public function createShipment(SalesDeliveryNote $note): ShipmentResult
        {
            return new ShipmentResult('ACME-123', 'https://track.acme.test/ACME-123', 'ext-1');
        }

        public function cancelShipment(SalesDeliveryNote $note): void
        {
            $this->cancelled = true;
        }

        public function generateCmr(SalesDeliveryNote $note): ?string
        {
            return 'cmr-'.$note->ddt_number;
        }
    };
    app(ShippingCarrierManager::class)->extend($driver);

    $acme = $this->ddt->startTransit($this->ddt->issue(lifecycleDraft($this, ['carrier_name' => 'ACME Express'])));
    expect($acme->tracking_number)->toBe('ACME-123')->and($acme->carrier_tracking_url)->toBe('https://track.acme.test/ACME-123')
        ->and(app(ShippingCarrierManager::class)->driverFor($acme)->generateCmr($acme))->toBe('cmr-'.$acme->ddt_number);

    $this->ddt->cancel($acme);
    expect($driver->cancelled)->toBeTrue();

    // Other carriers fall back to the manual driver (no remote call, operator-provided tracking is kept)
    $manual = $this->ddt->startTransit($this->ddt->issue(lifecycleDraft($this, ['carrier_name' => 'BRT', 'tracking_number' => 'T-1'])));
    expect(app(ShippingCarrierManager::class)->driverFor($manual)->code())->toBe('manual')->and($manual->tracking_number)->toBe('T-1');
});

test('the logistics:run-deferred-invoicing command bills every tenant', function (): void {
    $note = $this->ddt->issue(lifecycleDraft($this));
    app(TenantContext::class)->clear();

    $this->artisan('logistics:run-deferred-invoicing', ['--tenant' => $this->tenant->id])->assertSuccessful();

    app(TenantContext::class)->setTenant($this->tenant);
    expect($note->fresh()->status)->toBe('invoiced');
});
