<?php

declare(strict_types=1);

use App\Contracts\PdfGenerator;
use App\Models\DeliveryNote as LegacyDeliveryNote;
use App\Services\Pdf\DocumentPdfService;
use App\Services\SwissQrBill\SwissQrBillService;
use App\Services\Tenancy\TenantContext;
use Database\Seeders\TranslationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Invoicing\Models\SalesDeliveryNote;
use Modules\Logistics\Services\DeliveryNoteService;
use Modules\Logistics\Services\OrderBillingService;
use Modules\Logistics\Services\WarehouseStockService;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    ['tenant' => $this->tenant, 'user' => $this->user] = tfrTenant('Tracking');
    app(TenantContext::class)->setTenant($this->tenant);
    $this->customer = tfrCustomer($this->tenant->id);
    $this->product = tfrProduct($this->tenant->id, 'TRK-1', 10, 50);
    $this->warehouse = tfrWarehouse($this->tenant->id, 'MAIN');
    app(WarehouseStockService::class)->receiveOpeningStock($this->warehouse->id, $this->product->id, 100);
    $this->ddt = app(DeliveryNoteService::class);
});

function trackingDraft(object $t, array $extra = []): SalesDeliveryNote
{
    return $t->ddt->create(array_merge([
        'type' => 'outbound_sale', 'contact_id' => $t->customer->id, 'origin_warehouse_id' => $t->warehouse->id,
    ], $extra), [['product_id' => $t->product->id, 'description' => 'Widget', 'quantity' => 2, 'unit_price' => 50, 'tax_rate' => 22]]);
}

test('every DDT gets a unique generated tracking code that clients cannot overwrite', function (): void {
    $a = trackingDraft($this, ['tracking_code' => 'FORGED']);
    $b = trackingDraft($this);
    $code = $a->tracking_code;

    expect($code)->toMatch('/^FF\d{2}[A-Z2-9]{8}$/')->not->toBe('FORGED')
        ->and($b->tracking_code)->not->toBe($code)
        ->and($a->shipment_flow)->toBe('direct')->and($a->sender_display)->toBe('company');

    $this->ddt->update($a, ['tracking_code' => 'FORGED2', 'carrier_name' => 'GLS']);
    expect($a->fresh()->tracking_code)->toBe($code);
});

test('the carrier waybill (AWB) can be linked after the DDT is issued and dispatched, but not once cancelled', function (): void {
    $note = $this->ddt->startTransit($this->ddt->issue(trackingDraft($this)));

    $note = $this->ddt->assignAwb($note, ' 176-12345675 ', 'https://carrier.example/t/176-12345675');
    expect($note->awb_number)->toBe('176-12345675')->and($note->carrier_tracking_url)->toBe('https://carrier.example/t/176-12345675');

    $draft = trackingDraft($this);
    $this->ddt->cancel($draft);
    expect(fn () => $this->ddt->assignAwb($draft->fresh(), 'X1'))->toThrow(DomainException::class);
});

test('the AWB endpoint validates the payload and stores the waybill', function (): void {
    $note = $this->ddt->issue(trackingDraft($this));
    $h = ['X-Tenant-Id' => (string) $this->tenant->id];

    Sanctum::actingAs(tfrUserWithRole($this->tenant->id, 'viewer'));
    $this->postJson("/api/v1/logistics/delivery-notes/{$note->id}/awb", ['awb_number' => 'AWB-9'], $h)->assertForbidden();

    Sanctum::actingAs($this->user);
    $this->postJson("/api/v1/logistics/delivery-notes/{$note->id}/awb", [], $h)->assertUnprocessable();
    $this->postJson("/api/v1/logistics/delivery-notes/{$note->id}/awb", ['awb_number' => 'AWB-9'], $h)
        ->assertOk()->assertJsonPath('data.awb_number', 'AWB-9');
});

test('triangular and dropshipping DDTs need the final recipient, and a custom sender needs a name', function (): void {
    expect(fn () => trackingDraft($this, ['shipment_flow' => 'dropshipping']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => trackingDraft($this, ['shipment_flow' => 'unknown']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => trackingDraft($this, ['shipment_flow' => 'triangular', 'final_recipient_name' => 'Rossi Srl', 'sender_display' => 'custom']))->toThrow(InvalidArgumentException::class);

    $note = trackingDraft($this, [
        'shipment_flow' => 'dropshipping', 'final_recipient_name' => 'Rossi Srl', 'shipping_address' => 'Via Roma 1, Milano',
        'sender_display' => 'custom', 'sender_name' => 'Magazzino Terzo Srl', 'sender_address' => "Via Industria 5\n20100 Milano",
    ]);

    expect($note->shipment_flow)->toBe('dropshipping')->and($note->final_recipient_name)->toBe('Rossi Srl');
});

test('the PDF prints a custom or blank sender instead of the issuer on triangular shipments', function (): void {
    $this->seed(TranslationSeeder::class);
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
    $html = function (SalesDeliveryNote $note) use ($service, $fake): string {
        $service->render(LegacyDeliveryNote::query()->findOrFail($note->id), ['locale' => 'it']);
        $call = $fake->calls[array_key_last($fake->calls)];
        app()->setLocale('it');

        return view($call['view'], $call['data'])->render();
    };
    $issuer = (string) ($this->tenant->legal_name ?: $this->tenant->name);

    $direct = trackingDraft($this, ['awb_number' => 'AWB-77']);
    expect($html($direct))->toContain($issuer)->toContain($direct->tracking_code)->toContain('AWB-77')->not->toContain('Dropshipping');

    $custom = trackingDraft($this, ['shipment_flow' => 'dropshipping', 'final_recipient_name' => 'Rossi Srl', 'sender_display' => 'custom', 'sender_name' => 'Magazzino Terzo Srl', 'sender_address' => 'Via Industria 5']);
    expect($html($custom))->toContain('Magazzino Terzo Srl')->toContain('Via Industria 5')->toContain('Dropshipping')->toContain('Rossi Srl')->not->toContain($issuer);

    $blank = trackingDraft($this, ['shipment_flow' => 'triangular', 'final_recipient_name' => 'Rossi Srl', 'sender_display' => 'blank']);
    expect($html($blank))->not->toContain($issuer)->toContain('Triangolazione');
});

test('goods sent on approval (conto visione) are not billable until the customer accepts them', function (): void {
    $this->travelTo('2026-05-10 09:00:00');
    $onApproval = $this->ddt->issue(trackingDraft($this, ['transport_reason_code' => 'on_approval']));
    $sale = $this->ddt->issue(trackingDraft($this));
    $billing = app(OrderBillingService::class);

    expect($onApproval->transport_reason)->toBe('Conto visione')->and($onApproval->isBillable())->toBeFalse();
    expect(fn () => $billing->issueDeferredInvoice([(string) $onApproval->id]))->toThrow(DomainException::class);
    expect(collect($billing->unbilledByCustomer())->pluck('delivery_notes')->flatten(1)->pluck('id')->all())->toBe([(string) $sale->id]);

    $invoices = $billing->runDeferredInvoicing();
    expect($invoices)->toHaveCount(1)->and($invoices[0]->items)->toHaveCount(1)
        ->and($onApproval->fresh()->status)->toBe('issued');

    $accepted = $this->ddt->acceptOnApproval($onApproval);
    expect($accepted->transport_reason_code)->toBe('sale')->and($accepted->isBillable())->toBeTrue();
    expect($billing->runDeferredInvoicing())->toHaveCount(1)->and($onApproval->fresh()->status)->toBe('invoiced');

    expect(fn () => $this->ddt->acceptOnApproval($sale))->toThrow(DomainException::class);
});
