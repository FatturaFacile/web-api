<?php

declare(strict_types=1);

use App\Actions\Sales\RecordPayment;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\SwissEbill\Application\Services\EbillDeliveryService;
use Modules\SwissEbill\Models\EbillTransmission;
use Modules\SwissEbill\Models\EbillTransmissionEvent;

require_once __DIR__.'/EbillSupport.php';

uses(RefreshDatabase::class);

beforeEach(fn () => ebSetUp($this));

function ebPoll(object $test): void
{
    $test->artisan('ebill:poll-pending-statuses')->assertSuccessful();
}

/**
 * @return list<string>
 */
function ebEventTypes(EbillTransmission $transmission): array
{
    return $transmission->events()->pluck('event_type')->all();
}

test('sending an invoice stores the payload, hands it to the gateway and updates the invoice summary', function (): void {
    $invoice = ebInvoice(ebCustomer());

    $transmission = ebSend($this, $invoice);

    expect($transmission->status->value)->toBe('transmitted')
        ->and($transmission->six_transaction_id)->toStartWith('SBX-')
        ->and($transmission->submission_timestamp)->not->toBeNull()
        ->and($transmission->attempt_count)->toBe(1)
        ->and($transmission->channel_type->value)->toBe('b2b_enterprise')
        ->and($transmission->biller_id)->toBe(ebBillerId())
        ->and($transmission->recipient_id)->toBe($invoice->contact->ebill_recipient_id)
        ->and((float) $transmission->invoice_amount)->toBe(108.1)
        ->and($transmission->currency)->toBe('CHF')
        ->and($transmission->xml_sha256)->toBe(hash('sha256', (string) $transmission->xml_payload))
        ->and($transmission->pdf_sha256)->toBe(hash('sha256', '%PDF-1.4 courtesy'))
        ->and(ebEventTypes($transmission))->toBe(['prepared', 'queued', 'transmitted'])
        ->and($invoice->refresh()->ebill_status)->toBe('transmitted')
        ->and($invoice->ebill_transmission_id)->toBe($transmission->id)
        ->and($invoice->ebill_last_event_at)->not->toBeNull();
});

test('the idempotency key is stable and sent to the gateway once', function (): void {
    $transmission = ebSend($this, ebInvoice(ebCustomer()));

    expect($transmission->idempotency_key)->toStartWith('inv-'.$transmission->invoice_id.'-');

    app(EbillDeliveryService::class)->deliver($transmission->id);

    expect($transmission->refresh()->attempt_count)->toBe(1)->and(ebEventTypes($transmission))->toBe(['prepared', 'queued', 'transmitted']);
});

test('an invoice cannot be sent twice while its eBill is alive', function (): void {
    $invoice = ebInvoice(ebCustomer());
    ebSend($this, $invoice);

    $this->postJson('/api/v1/swiss-ebill/invoices/'.$invoice->id.'/send')->assertStatus(409)->assertJsonPath('code', 'already_transmitted');
    expect(EbillTransmission::query()->count())->toBe(1);
});

test('sending needs the channel to be enabled', function (): void {
    $invoice = ebInvoice(ebCustomer());
    ebSettings(['ebill_enabled' => false]);

    $this->postJson('/api/v1/swiss-ebill/invoices/'.$invoice->id.'/send')->assertStatus(422)->assertJsonPath('code', 'ebill_disabled');
});

test('the polling fallback walks the transmission to delivered and approved', function (): void {
    $invoice = ebInvoice(ebCustomer());
    $transmission = ebSend($this, $invoice);

    ebPoll($this);
    expect($transmission->refresh()->status->value)->toBe('transmitted');

    ebAdvance($this, 2);
    ebPoll($this);
    expect($transmission->refresh()->status->value)->toBe('delivered_to_ebanking')
        ->and($transmission->delivered_at)->not->toBeNull()
        ->and($invoice->refresh()->ebill_status)->toBe('delivered_to_ebanking');

    ebAdvance($this, 2);
    ebPoll($this);
    $transmission->refresh();

    expect($transmission->status->value)->toBe('approved_by_debtor')
        ->and($transmission->approved_at)->not->toBeNull()
        ->and($transmission->scheduled_payment_date?->toDateString())->toBe('2026-03-10')
        ->and($invoice->refresh()->ebill_status)->toBe('approved_by_debtor')
        ->and(ebEventTypes($transmission))->toBe(['prepared', 'queued', 'transmitted', 'delivered_to_ebanking', 'approved_by_debtor']);
});

test('polling twice does not repeat an event', function (): void {
    $transmission = ebSend($this, ebInvoice(ebCustomer()));
    ebAdvance($this, 5);

    ebPoll($this);
    ebPoll($this);

    expect(ebEventTypes($transmission->refresh()))->toBe(['prepared', 'queued', 'transmitted', 'delivered_to_ebanking', 'approved_by_debtor']);
});

test('a debtor rejection stores the bank code and the note and notifies accounting', function (): void {
    $invoice = ebInvoice(ebCustomer(['company_name' => 'Rejecting AG'], '9'));
    $transmission = ebSend($this, $invoice);
    $admin = ebUser($this->tenant, 'admin');
    $member = ebUser($this->tenant, 'sales');

    ebAdvance($this, 5);
    ebPoll($this);
    $transmission->refresh();

    expect($transmission->status->value)->toBe('rejected_by_debtor')
        ->and($transmission->rejection_code)->toBe('AMOUNT_MISMATCH')
        ->and($transmission->rejection_reason)->toBe('The amount does not match the order.')
        ->and($transmission->rejected_at)->not->toBeNull()
        ->and($invoice->refresh()->ebill_status)->toBe('rejected_by_debtor');

    $notification = $this->actor->notifications()->first();

    expect($notification)->not->toBeNull()
        ->and($notification->data['key'])->toBe('ebill_rejected')
        ->and($notification->data['invoice_number'])->toBe($invoice->invoice_number)
        ->and($notification->data['customer_name'])->toBe('Rejecting AG')
        ->and($notification->data['rejection_code'])->toBe('AMOUNT_MISMATCH')
        ->and($admin->notifications()->count())->toBe(1)
        ->and($member->notifications()->count())->toBe(0);
});

test('a rejected eBill is revised into a new transmission that points to the old one', function (): void {
    $invoice = ebInvoice(ebCustomer([], '9'));
    $rejected = ebSend($this, $invoice);
    ebAdvance($this, 5);
    ebPoll($this);

    $response = $this->postJson('/api/v1/swiss-ebill/transmissions/'.$rejected->id.'/revise')->assertStatus(202)->assertJsonPath('data.supersedes_id', $rejected->id);
    $revised = EbillTransmission::query()->findOrFail($response->json('data.id'));
    app(EbillDeliveryService::class)->deliver($revised->id);

    expect($revised->refresh()->status->value)->toBe('transmitted')
        ->and($rejected->refresh()->status->value)->toBe('rejected_by_debtor')
        ->and($invoice->refresh()->ebill_transmission_id)->toBe($revised->id)
        ->and($invoice->ebill_status)->toBe('transmitted')
        ->and($revised->idempotency_key)->not->toBe($rejected->idempotency_key);
});

test('a live transmission cannot be revised', function (): void {
    $transmission = ebSend($this, ebInvoice(ebCustomer()));

    $this->postJson('/api/v1/swiss-ebill/transmissions/'.$transmission->id.'/revise')->assertStatus(409)->assertJsonPath('code', 'already_transmitted');
});

test('an eBill is recalled before the debtor approves it', function (): void {
    $invoice = ebInvoice(ebCustomer());
    $transmission = ebSend($this, $invoice);

    $this->postJson('/api/v1/swiss-ebill/transmissions/'.$transmission->id.'/cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');

    expect($transmission->refresh()->cancelled_at)->not->toBeNull()
        ->and($invoice->refresh()->ebill_status)->toBe('cancelled')
        ->and(ebEventTypes($transmission))->toBe(['prepared', 'queued', 'transmitted', 'cancelled']);

    // The invoice can go out again once the old transmission is cancelled.
    $this->postJson('/api/v1/swiss-ebill/invoices/'.$invoice->id.'/send')->assertStatus(202);
});

test('an approved eBill can no longer be recalled', function (): void {
    $transmission = ebSend($this, ebInvoice(ebCustomer()));
    ebAdvance($this, 5);
    ebPoll($this);

    $this->postJson('/api/v1/swiss-ebill/transmissions/'.$transmission->id.'/cancel')->assertStatus(409)->assertJsonPath('code', 'cannot_cancel');
    expect($transmission->refresh()->status->value)->toBe('approved_by_debtor');
});

test('the transmission log is filtered by status, customer, channel, dates and search', function (): void {
    $b2b = ebCustomer(['company_name' => 'Alpha AG']);
    $b2c = ebCustomer(['company_name' => 'Beta Privat', 'uid_number' => null, 'ebill_channel_type' => 'b2c_private'], '8');
    $first = ebSend($this, ebInvoice($b2b));
    $second = ebSend($this, ebInvoice($b2c));

    ebAdvance($this, 5);
    ebPoll($this);

    $all = $this->getJson('/api/v1/swiss-ebill/transmissions')->assertOk()->assertJsonPath('meta.total', 2);
    expect(array_column($all->json('data'), 'customer_name'))->toContain('Alpha AG', 'Beta Privat');

    $this->getJson('/api/v1/swiss-ebill/transmissions?ebill_status=approved_by_debtor')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $first->id);
    $this->getJson('/api/v1/swiss-ebill/transmissions?ebill_status=delivered_to_ebanking')->assertOk()->assertJsonPath('data.0.id', $second->id);
    $this->getJson('/api/v1/swiss-ebill/transmissions?channel_type=b2c_private')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $second->id);
    $this->getJson('/api/v1/swiss-ebill/transmissions?customer_id='.$b2b->id)->assertOk()->assertJsonPath('meta.total', 1);
    $this->getJson('/api/v1/swiss-ebill/transmissions?invoice_id='.$first->invoice_id)->assertOk()->assertJsonPath('meta.total', 1);
    $this->getJson('/api/v1/swiss-ebill/transmissions?search='.strtolower((string) $first->six_transaction_id))->assertOk()->assertJsonPath('meta.total', 1);
    $this->getJson('/api/v1/swiss-ebill/transmissions?from=2026-03-06')->assertOk()->assertJsonPath('meta.total', 0);
    $this->getJson('/api/v1/swiss-ebill/transmissions?from=2026-03-01&to=2026-03-31')->assertOk()->assertJsonPath('meta.total', 2);
    $this->getJson('/api/v1/swiss-ebill/transmissions?ebill_status=bogus')->assertStatus(422);
});

test('the dashboard counts every stage', function (): void {
    ebSend($this, ebInvoice(ebCustomer()));
    ebSend($this, ebInvoice(ebCustomer([], '9')));
    ebAdvance($this, 5);
    ebPoll($this);
    ebSend($this, ebInvoice(ebCustomer(), [], 200.0));
    $paid = ebSend($this, ebInvoice(ebCustomer(), [], 300.0));

    app(RecordPayment::class)->execute($paid->invoice, ['amount' => 324.3, 'paid_at' => '2026-03-05', 'reference' => 'DASH-1']);

    $kpis = $this->getJson('/api/v1/swiss-ebill/dashboard')->assertOk()->json('data');

    expect($kpis['total_transmitted']['count'])->toBe(4)
        ->and($kpis['awaiting_approval']['count'])->toBe(1)
        ->and($kpis['approved']['count'])->toBe(1)
        ->and($kpis['rejected']['count'])->toBe(1)
        ->and($kpis['reconciled']['count'])->toBe(1)
        ->and($kpis['failed']['count'])->toBe(0)
        ->and($kpis['approved']['amounts']['CHF'])->toBe(108.1)
        ->and($kpis['reconciled']['amounts']['CHF'])->toBe(324.3);
});

test('the invoice lifecycle shows the transmissions newest first with their timeline', function (): void {
    $invoice = ebInvoice(ebCustomer([], '9'));
    $first = ebSend($this, $invoice);
    ebAdvance($this, 5);
    ebPoll($this);
    $this->postJson('/api/v1/swiss-ebill/transmissions/'.$first->id.'/revise')->assertStatus(202);

    $response = $this->getJson('/api/v1/swiss-ebill/invoices/'.$invoice->id)->assertOk();

    expect($response->json('data.transmissions'))->toHaveCount(2)
        ->and($response->json('data.transmissions.1.id'))->toBe($first->id)
        ->and(array_column($response->json('data.transmissions.1.events'), 'event_type'))->toBe(['prepared', 'queued', 'transmitted', 'delivered_to_ebanking', 'rejected_by_debtor'])
        ->and($response->json('data.transmissions.1.rejection_code'))->toBe('AMOUNT_MISMATCH')
        ->and($response->json('data.can_send'))->toBeFalse();
});

test('one transmission shows its gateway ids, the audit hashes and the available actions', function (): void {
    $transmission = ebSend($this, ebInvoice(ebCustomer()));

    $this->getJson('/api/v1/swiss-ebill/transmissions/'.$transmission->id)->assertOk()
        ->assertJsonPath('data.six_transaction_id', $transmission->six_transaction_id)
        ->assertJsonPath('data.xml_sha256', $transmission->xml_sha256)
        ->assertJsonPath('data.pdf_sha256', hash('sha256', '%PDF-1.4 courtesy'))
        ->assertJsonPath('data.can_cancel', true)
        ->assertJsonPath('data.can_retry', false)
        ->assertJsonPath('data.can_revise', false)
        ->assertJsonMissingPath('data.xml_payload');
});

test('the transmitted XML can be downloaded for audit and the download is recorded', function (): void {
    $transmission = ebSend($this, ebInvoice(ebCustomer()));

    $response = $this->get('/api/v1/swiss-ebill/transmissions/'.$transmission->id.'/payload')->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('application/xml')
        ->and($response->headers->get('X-Payload-Sha256'))->toBe($transmission->xml_sha256)
        ->and($response->getContent())->toBe($transmission->xml_payload)
        ->and(ebEventTypes($transmission->refresh()))->toContain('payload_downloaded')
        ->and(EbillTransmissionEvent::query()->where('event_type', 'payload_downloaded')->first()->created_by)->toBe($this->actor->id);
});

test('the event trail is append-only', function (): void {
    $transmission = ebSend($this, ebInvoice(ebCustomer()));
    $event = $transmission->events()->first();

    expect(fn () => $event->update(['event_type' => 'tampered']))->toThrow(LogicException::class)
        ->and(fn () => $event->delete())->toThrow(LogicException::class);
});

test('a transmission of another company is invisible', function (): void {
    $transmission = ebSend($this, ebInvoice(ebCustomer()));
    app(TenantContext::class)->setTenant($this->otherTenant);
    $this->withHeader('X-Tenant-Id', $this->otherTenant->id);
    $this->actor->tenants()->attach($this->otherTenant->id, ['id' => (string) Str::uuid(), 'role' => 'accountant', 'permissions' => json_encode([])]);

    $this->getJson('/api/v1/swiss-ebill/transmissions/'.$transmission->id)->assertNotFound();
    $this->getJson('/api/v1/swiss-ebill/transmissions')->assertOk()->assertJsonPath('meta.total', 0);
    $this->postJson('/api/v1/swiss-ebill/transmissions/'.$transmission->id.'/cancel')->assertNotFound();
});

test('sales can follow the delivery but cannot send, cancel or retry', function (): void {
    $invoice = ebInvoice(ebCustomer());
    $transmission = ebSend($this, $invoice);
    $this->actor = ebUser($this->tenant, 'sales');
    Sanctum::actingAs($this->actor);

    $this->getJson('/api/v1/swiss-ebill/transmissions')->assertOk();
    $this->getJson('/api/v1/swiss-ebill/invoices/'.$invoice->id)->assertOk();
    $this->postJson('/api/v1/swiss-ebill/invoices/'.$invoice->id.'/send')->assertForbidden();
    $this->postJson('/api/v1/swiss-ebill/transmissions/'.$transmission->id.'/cancel')->assertForbidden();
    $this->postJson('/api/v1/swiss-ebill/transmissions/'.$transmission->id.'/retry')->assertForbidden();
});

test('the polling command only looks at companies with eBill enabled', function (): void {
    $transmission = ebSend($this, ebInvoice(ebCustomer()));
    ebAdvance($this, 5);
    ebSettings(['ebill_enabled' => false]);

    ebPoll($this);

    expect($transmission->refresh()->status->value)->toBe('transmitted');
});
