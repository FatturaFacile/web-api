<?php

declare(strict_types=1);

use App\Jobs\SendPaymentReminderEmail;
use App\Jobs\SendSubscriptionRenewalNotice;
use App\Mail\PaymentReminderMail;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoiceQrBillData;
use App\Models\PaymentReminder;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Pdf\DocumentPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
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
        'email' => 'customer@example.com',
    ]);
});

function opsApi()
{
    return test()->withHeader('X-Tenant-ID', test()->tenant->id);
}

function makeInvoice(array $attributes = []): Invoice
{
    return Invoice::create(array_merge([
        'tenant_id' => test()->tenant->id,
        'contact_id' => test()->contact->id,
        'invoice_type' => 'standard',
        'invoice_number' => 'INV-2026-'.random_int(1000, 9999),
        'date' => '2026-01-10',
        'due_date' => '2026-02-10',
        'currency' => 'EUR',
        'total_net' => 100,
        'total_tax' => 22,
        'total_gross' => 122,
        'jurisdiction' => 'IT',
    ], $attributes));
}

// ---------------------------------------------------------------- Alerts

test('alerts are shown globally or on their target, only while in force', function (): void {
    $other = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Other Customer', 'country' => 'IT']);

    opsApi()->postJson('/api/v1/alerts', ['title' => 'Closed for holidays', 'severity' => 'info'])->assertCreated();
    opsApi()->postJson('/api/v1/alerts', [
        'title' => 'Bad payer', 'severity' => 'critical', 'alertable_type' => 'contact', 'alertable_id' => $this->contact->id,
    ])->assertCreated();
    opsApi()->postJson('/api/v1/alerts', [
        'title' => 'Expired', 'alertable_type' => 'contact', 'alertable_id' => $this->contact->id, 'ends_at' => '2026-03-01',
    ])->assertCreated();

    $titles = fn (array $query) => collect(opsApi()->getJson('/api/v1/alerts/active?'.http_build_query($query))->assertOk()->json('data'))->pluck('title')->all();

    expect($titles(['alertable_type' => 'contact', 'alertable_id' => $this->contact->id]))->toBe(['Bad payer', 'Closed for holidays'])
        ->and($titles(['alertable_type' => 'contact', 'alertable_id' => $other->id]))->toBe(['Closed for holidays']);
});

test('alert validation rejects unknown target types and half-specified targets', function (): void {
    opsApi()->postJson('/api/v1/alerts', ['title' => 'x', 'alertable_type' => 'spaceship', 'alertable_id' => (string) Str::uuid()])
        ->assertUnprocessable()->assertJsonValidationErrors('alertable_type');
    opsApi()->postJson('/api/v1/alerts', ['title' => 'x', 'alertable_type' => 'contact'])
        ->assertUnprocessable()->assertJsonValidationErrors('alertable_id');
    opsApi()->postJson('/api/v1/alerts', ['title' => 'x', 'alertable_type' => 'contact', 'alertable_id' => (string) Str::uuid()])
        ->assertNotFound();
});

// ---------------------------------------------------------------- Loyalty

test('loyalty card keeps a consistent balance and rejects invalid movements', function (): void {
    $card = opsApi()->postJson('/api/v1/loyalty-cards', [
        'type' => 'prepaid', 'contact_id' => $this->contact->id, 'initial_balance' => 50,
    ])->assertCreated();
    $id = $card->json('data.id');

    expect($card->json('data.card_number'))->toBe('LOY-2026-0001')
        ->and((float) $card->json('data.balance'))->toEqual(50.0);

    $redeem = opsApi()->postJson("/api/v1/loyalty-cards/{$id}/transactions", ['type' => 'redeem', 'amount' => 20])->assertCreated();
    expect((float) $redeem->json('data.amount'))->toEqual(-20.0)->and((float) $redeem->json('data.balance_after'))->toEqual(30.0);

    opsApi()->postJson("/api/v1/loyalty-cards/{$id}/transactions", ['type' => 'redeem', 'amount' => 100])->assertUnprocessable();
    opsApi()->postJson("/api/v1/loyalty-cards/{$id}/transactions", ['type' => 'earn', 'amount' => 0])->assertUnprocessable();
    opsApi()->postJson("/api/v1/loyalty-cards/{$id}/transactions", ['type' => 'adjust', 'amount' => -40])->assertUnprocessable();

    expect((float) opsApi()->getJson("/api/v1/loyalty-cards/{$id}")->json('data.balance'))->toEqual(30.0)
        ->and(opsApi()->getJson("/api/v1/loyalty-cards/{$id}/transactions")->json('data'))->toHaveCount(2);

    opsApi()->putJson("/api/v1/loyalty-cards/{$id}", ['status' => 'blocked'])->assertOk();
    opsApi()->postJson("/api/v1/loyalty-cards/{$id}/transactions", ['type' => 'earn', 'amount' => 5])->assertUnprocessable();
});

test('expired loyalty cards reject movements and card numbers are unique per tenant', function (): void {
    $id = opsApi()->postJson('/api/v1/loyalty-cards', ['type' => 'voucher', 'card_number' => 'GIFT-1', 'expires_at' => '2026-03-01'])->json('data.id');

    opsApi()->postJson('/api/v1/loyalty-cards', ['type' => 'voucher', 'card_number' => 'GIFT-1'])
        ->assertUnprocessable()->assertJsonValidationErrors('card_number');
    opsApi()->postJson("/api/v1/loyalty-cards/{$id}/transactions", ['type' => 'topup', 'amount' => 10])->assertUnprocessable();
    expect(opsApi()->getJson("/api/v1/loyalty-cards/{$id}")->json('data.status'))->toBe('expired');
});

// ---------------------------------------------------------------- Reminders

test('manual reminders escalate to the maximum level and queue an email job', function (): void {
    Queue::fake();
    $invoice = makeInvoice();

    $levels = [];
    foreach (range(1, 4) as $_) {
        $levels[] = opsApi()->postJson("/api/v1/sales/invoices/{$invoice->id}/reminders")->assertCreated()->json('data.level');
    }

    expect($levels)->toBe([1, 2, 3, 3]);
    Queue::assertPushed(SendPaymentReminderEmail::class, 4);
    expect(DB::table('document_events')->where('eventable_id', $invoice->id)->where('event', 'reminder_queued')->count())->toBe(4)
        ->and(opsApi()->getJson("/api/v1/sales/invoices/{$invoice->id}/reminders")->json('data'))->toHaveCount(4);
});

test('reminders are refused for paid invoices, proformas and contacts without email', function (): void {
    Queue::fake();
    $paid = makeInvoice(['payment_status' => 'paid', 'total_paid' => 122]);
    $proforma = makeInvoice(['invoice_type' => 'proforma']);
    $noEmail = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'No Email Customer', 'country' => 'IT']);
    $orphan = makeInvoice(['contact_id' => $noEmail->id]);

    foreach ([$paid, $proforma, $orphan] as $invoice) {
        opsApi()->postJson("/api/v1/sales/invoices/{$invoice->id}/reminders")->assertUnprocessable();
    }

    Queue::assertNothingPushed();
});

test('reminder job sends the mailable and marks the reminder as sent', function (): void {
    Mail::fake();
    $invoice = makeInvoice();
    $reminder = PaymentReminder::create([
        'tenant_id' => $this->tenant->id, 'invoice_id' => $invoice->id, 'level' => 2,
        'recipient' => 'customer@example.com', 'amount_due' => 122,
    ]);

    (new SendPaymentReminderEmail($reminder->id))->handle(app(DocumentPdfService::class));

    Mail::assertSent(PaymentReminderMail::class, fn ($mail) => $mail->hasTo('customer@example.com'));
    expect($reminder->refresh()->status)->toBe('sent')->and($reminder->sent_at)->not->toBeNull();
});

test('scheduled command queues reminders only for overdue invoices and respects the interval', function (): void {
    Queue::fake();
    makeInvoice();
    makeInvoice(['due_date' => '2026-04-30']); // not yet due
    makeInvoice(['payment_status' => 'paid', 'total_paid' => 122]);

    $this->artisan('invoices:send-reminders')->assertSuccessful();
    $this->artisan('invoices:send-reminders')->assertSuccessful(); // same day: interval not elapsed

    Queue::assertPushed(SendPaymentReminderEmail::class, 1);

    $this->travelTo('2026-03-31 10:00:00'); // 16 days later
    $this->artisan('invoices:send-reminders')->assertSuccessful();
    Queue::assertPushed(SendPaymentReminderEmail::class, 2);
    expect(PaymentReminder::pluck('level')->sort()->values()->all())->toBe([1, 2]);
});

test('renewal notice dispatches an email job when the contact has an address', function (): void {
    Queue::fake();
    opsApi()->postJson('/api/v1/subscriptions', [
        'contact_id' => $this->contact->id, 'name' => 'Hosting', 'interval' => 'monthly', 'currency' => 'EUR',
        'start_date' => '2026-03-15', 'end_date' => '2026-05-10', 'notify_days_before' => 60,
        'items' => [['description' => 'Hosting', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 22]],
    ])->assertCreated();

    $this->artisan('subscriptions:process')->assertSuccessful();
    $this->artisan('subscriptions:process')->assertSuccessful();

    Queue::assertPushed(SendSubscriptionRenewalNotice::class, 1);
});

// ---------------------------------------------------------------- Bank payment import

function camtXml(): string
{
    return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.054.001.04">
  <BkToCstmrDbtCdtNtfctn><Ntfctn>
    <Ntry>
      <Amt Ccy="CHF">121.80</Amt><CdtDbtInd>CRDT</CdtDbtInd><BookgDt><Dt>2026-03-10</Dt></BookgDt>
      <NtryDtls><TxDtls>
        <Refs><AcctSvcrRef>REF-1</AcctSvcrRef></Refs>
        <RltdPties><Dbtr><Nm>Zurich Customer AG</Nm></Dbtr></RltdPties>
        <RmtInf><Strd><CdtrRefInf><Ref>RF18 5390 0754 7034</Ref></CdtrRefInf></Strd></RmtInf>
      </TxDtls></NtryDtls>
    </Ntry>
    <Ntry>
      <Amt Ccy="CHF">50.00</Amt><CdtDbtInd>CRDT</CdtDbtInd><BookgDt><Dt>2026-03-11</Dt></BookgDt>
      <NtryDtls><TxDtls>
        <Refs><AcctSvcrRef>REF-2</AcctSvcrRef></Refs>
        <RmtInf><Ustrd>Payment for invoice CH-2026-0002 thanks</Ustrd></RmtInf>
      </TxDtls></NtryDtls>
    </Ntry>
    <Ntry>
      <Amt Ccy="CHF">10.00</Amt><CdtDbtInd>CRDT</CdtDbtInd><BookgDt><Dt>2026-03-12</Dt></BookgDt>
      <NtryDtls><TxDtls><Refs><AcctSvcrRef>REF-3</AcctSvcrRef></Refs><RmtInf><Ustrd>Unknown deposit</Ustrd></RmtInf></TxDtls></NtryDtls>
    </Ntry>
    <Ntry>
      <Amt Ccy="CHF">99.00</Amt><CdtDbtInd>DBIT</CdtDbtInd><BookgDt><Dt>2026-03-12</Dt></BookgDt>
    </Ntry>
  </Ntfctn></BkToCstmrDbtCdtNtfctn>
</Document>
XML;
}

function chfInvoice(string $number, float $gross, ?string $scor = null): Invoice
{
    $invoice = makeInvoice([
        'invoice_type' => 'ch_qr_bill', 'jurisdiction' => 'CH', 'currency' => 'CHF',
        'invoice_number' => $number, 'total_net' => $gross, 'total_tax' => 0, 'total_gross' => $gross,
    ]);

    if ($scor !== null) {
        InvoiceQrBillData::create([
            'invoice_id' => $invoice->id, 'qr_iban' => 'CH4431999123000889012', 'scor_reference' => $scor,
            'creditor_info' => [], 'debtor_info' => [],
        ]);
    }

    return $invoice;
}

test('camt import matches by QR reference and invoice number, skips debits, and is idempotent', function (): void {
    $byReference = chfInvoice('CH-2026-0001', 121.80, 'RF18 5390 0754 7034');
    $byNumber = chfInvoice('CH-2026-0002', 50.00);

    $upload = fn () => UploadedFile::fake()->createWithContent('camt054.xml', camtXml());

    $import = opsApi()->postJson('/api/v1/sales/payment-imports', ['file' => $upload()])->assertCreated();

    expect($import->json('data.total_entries'))->toBe(3)
        ->and($import->json('data.matched_entries'))->toBe(2)
        ->and(collect($import->json('data.lines'))->pluck('status')->sort()->values()->all())->toBe(['matched', 'matched', 'unmatched']);

    expect($byReference->refresh()->payment_status)->toBe('paid')
        ->and($byNumber->refresh()->payment_status)->toBe('paid')
        ->and($byReference->payments()->first()->paid_at->toDateString())->toBe('2026-03-10');

    $again = opsApi()->postJson('/api/v1/sales/payment-imports', ['file' => $upload()])->assertCreated();
    expect($again->json('data.duplicate_entries'))->toBe(3)->and($again->json('data.lines'))->toBeEmpty();
    expect($byReference->payments()->count())->toBe(1);
});

test('unmatched lines can be matched manually, but not twice nor beyond the outstanding balance', function (): void {
    $invoice = chfInvoice('CH-2026-0003', 8.00);
    $big = chfInvoice('CH-2026-0004', 500.00);

    $import = opsApi()->postJson('/api/v1/sales/payment-imports', [
        'file' => UploadedFile::fake()->createWithContent('camt054.xml', camtXml()),
    ])->assertCreated();
    $line = collect($import->json('data.lines'))->firstWhere('status', 'unmatched');

    // 10.00 CHF exceeds the 8.00 outstanding on the first invoice.
    opsApi()->postJson("/api/v1/sales/payment-imports/{$import->json('data.id')}/lines/{$line['id']}/match", ['invoice_id' => $invoice->id])
        ->assertUnprocessable();

    opsApi()->postJson("/api/v1/sales/payment-imports/{$import->json('data.id')}/lines/{$line['id']}/match", ['invoice_id' => $big->id])
        ->assertOk()->assertJsonPath('data.status', 'matched');
    expect($big->refresh()->payment_status)->toBe('partially_paid');

    opsApi()->postJson("/api/v1/sales/payment-imports/{$import->json('data.id')}/lines/{$line['id']}/match", ['invoice_id' => $big->id])
        ->assertUnprocessable();
});

test('invalid bank files are rejected with a problem response', function (): void {
    opsApi()->postJson('/api/v1/sales/payment-imports', [
        'file' => UploadedFile::fake()->createWithContent('bad.xml', '<foo>not camt</foo>'),
    ])->assertUnprocessable();

    opsApi()->postJson('/api/v1/sales/payment-imports', [])->assertUnprocessable()->assertJsonValidationErrors('file');
});
