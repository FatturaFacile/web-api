<?php

declare(strict_types=1);

use App\Jobs\SendAccountStatementEmail;
use App\Mail\AccountStatementMail;
use App\Models\Contact;
use App\Models\DocumentEmail;
use App\Models\Invoice;
use App\Models\PassiveInvoice;
use App\Models\Payment;
use App\Models\Quote;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Crm\ContactInsights;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    $user = User::create(['name' => 'Leonardo', 'email' => 'leo@fatturafacile.com', 'password' => bcrypt('secret123'), 'locale' => 'en']);
    $user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    Sanctum::actingAs($user);

    $this->contact = Contact::create(['tenant_id' => $this->tenant->id, 'type' => 'both', 'company_name' => 'Milan Customer SpA', 'country' => 'IT', 'email' => 'customer@example.com']);

    $make = function (string $number, string $date, ?string $due, float $net, float $gross, float $paid = 0, string $status = 'unpaid', string $type = 'standard'): Invoice {
        $invoice = Invoice::create([
            'tenant_id' => $this->tenant->id, 'contact_id' => $this->contact->id, 'invoice_type' => $type, 'invoice_number' => $number,
            'date' => $date, 'due_date' => $due, 'currency' => 'EUR', 'total_net' => $net, 'total_tax' => $gross - $net, 'total_gross' => $gross,
            'total_paid' => $paid, 'payment_status' => $status, 'jurisdiction' => 'IT',
        ]);

        if ($paid > 0) {
            Payment::create(['tenant_id' => $this->tenant->id, 'invoice_id' => $invoice->id, 'amount' => $paid, 'paid_at' => match ($number) {
                'INV-A' => '2026-02-01', 'INV-OLD' => '2025-06-11', default => '2026-02-20',
            }]);
        }

        return $invoice;
    };

    $make('INV-OLD', '2025-06-01', '2025-07-01', 50, 61, 61, 'paid');
    $make('INV-A', '2026-01-10', '2026-02-10', 100, 122, 122, 'paid');
    $this->partial = $make('INV-B', '2026-02-01', '2026-03-03', 200, 244, 100, 'partially_paid');
    $make('INV-C', '2026-03-10', '2026-04-10', 50, 61);
    $make('CN-1', '2026-03-12', null, 25, 30.5, 0, 'unpaid', 'credit_note');
    $make('PF-1', '2026-03-12', null, 900, 999, 0, 'unpaid', 'proforma');
});

function insightsApi()
{
    return test()->withHeader('X-Tenant-ID', test()->tenant->id);
}

test('statistics summarise invoiced, paid, outstanding, overdue and payment behaviour', function (): void {
    Quote::create(['tenant_id' => $this->tenant->id, 'contact_id' => $this->contact->id, 'quote_number' => 'QT-1', 'date' => '2026-03-01', 'currency' => 'EUR']);
    PassiveInvoice::create(['tenant_id' => $this->tenant->id, 'supplier_id' => $this->contact->id, 'invoice_number' => 'S-1', 'date' => '2026-03-01', 'total_amount' => 100, 'document_type' => 'TD01']);
    PassiveInvoice::create(['tenant_id' => $this->tenant->id, 'supplier_id' => $this->contact->id, 'invoice_number' => 'S-2', 'date' => '2026-03-02', 'total_amount' => 20, 'document_type' => 'TD04']);

    $stats = insightsApi()->getJson("/api/v1/crm/contacts/{$this->contact->id}/statistics")->assertOk()->json('data');

    expect($stats['invoice_count'])->toBe(4)
        ->and($stats['invoiced_total'])->toEqual(457.5)
        ->and($stats['paid_total'])->toEqual(283.0)
        ->and($stats['outstanding_total'])->toEqual(174.5)
        ->and($stats['overdue_total'])->toEqual(144.0)
        ->and($stats['average_invoice_value'])->toEqual(122.0)
        ->and($stats['first_invoice_date'])->toBe('2025-06-01')
        ->and($stats['last_invoice_date'])->toBe('2026-03-10')
        ->and($stats['average_days_to_pay'])->toEqual(16.0)
        ->and($stats['yearly_sales'])->toEqual(['2026' => 325.0, '2025' => 50.0])
        ->and($stats['documents']['quotes'])->toBe(1)
        ->and($stats['purchases'])->toEqual(['document_count' => 2, 'total' => 80.0]);
});

test('statistics of a contact without activity are empty but well formed', function (): void {
    $empty = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Brand New Customer', 'country' => 'IT']);

    $stats = insightsApi()->getJson("/api/v1/crm/contacts/{$empty->id}/statistics")->assertOk()->json('data');

    expect($stats['invoice_count'])->toBe(0)->and($stats['invoiced_total'])->toEqual(0.0)
        ->and($stats['average_days_to_pay'])->toBeNull()->and($stats['yearly_sales'])->toBeEmpty()
        ->and($stats['first_invoice_date'])->toBeNull();

    insightsApi()->getJson('/api/v1/crm/contacts/'.Str::uuid().'/statistics')->assertNotFound();
});

test('the statement lists open items with days overdue and ageing buckets', function (): void {
    $statement = insightsApi()->getJson("/api/v1/crm/contacts/{$this->contact->id}/statement")->assertOk()->json('data');

    expect($statement['as_of'])->toBe('2026-03-15')
        ->and(collect($statement['lines'])->pluck('invoice_number')->all())->toBe(['INV-B', 'INV-C'])
        ->and($statement['lines'][0]['balance'])->toEqual(144.0)
        ->and($statement['lines'][0]['days_overdue'])->toBe(12)
        ->and($statement['lines'][1]['days_overdue'])->toBe(0)
        ->and($statement['total_due'])->toEqual(205.0)
        ->and($statement['overdue_total'])->toEqual(144.0)
        ->and($statement['ageing'])->toEqual(['not_due' => 61.0, '1_30' => 144.0, '31_60' => 0.0, '61_90' => 0.0, 'over_90' => 0.0]);

    $earlier = insightsApi()->getJson("/api/v1/crm/contacts/{$this->contact->id}/statement?as_of=2026-02-20")->json('data');
    expect(collect($earlier['lines'])->pluck('invoice_number')->all())->toBe(['INV-B'])->and($earlier['overdue_total'])->toEqual(0.0);

    insightsApi()->getJson("/api/v1/crm/contacts/{$this->contact->id}/statement?as_of=garbage")->assertUnprocessable();
});

test('the statement is emailed through a queued job that renders translated text', function (): void {
    Queue::fake();
    $queued = insightsApi()->postJson("/api/v1/crm/contacts/{$this->contact->id}/statement/email")->assertStatus(202);

    expect($queued->json('data.subject'))->toBe('Account statement as of 2026-03-15')->and($queued->json('data.recipient'))->toBe('customer@example.com');
    Queue::assertPushed(SendAccountStatementEmail::class, 1);

    Mail::fake();
    (new SendAccountStatementEmail($queued->json('data.id'), '2026-03-15'))->handle(app(TenantContext::class), app(ContactInsights::class));

    Mail::assertSent(AccountStatementMail::class, fn (AccountStatementMail $mail): bool => $mail->hasTo('customer@example.com') && count($mail->statement['lines']) === 2);
    expect(DocumentEmail::find($queued->json('data.id'))->status)->toBe('sent');

    $mail = new AccountStatementMail($this->contact, app(ContactInsights::class)->statement($this->contact, now()));
    $mail->assertSeeInHtml('Dear Milan Customer SpA,')->assertSeeInHtml('INV-B')->assertSeeInHtml('Total due: 205.00')->assertSeeInHtml('Of which overdue: 144.00');
});

test('statements need a recipient address', function (): void {
    Queue::fake();
    $noEmail = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'No Email Customer', 'country' => 'IT']);

    insightsApi()->postJson("/api/v1/crm/contacts/{$noEmail->id}/statement/email")->assertUnprocessable()->assertJsonValidationErrors('to');
    insightsApi()->postJson("/api/v1/crm/contacts/{$noEmail->id}/statement/email", ['to' => 'accounts@example.com'])->assertStatus(202);
});
