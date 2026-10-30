<?php

declare(strict_types=1);

use App\Jobs\SendDocumentEmailJob;
use App\Mail\DocumentMail;
use App\Models\Contact;
use App\Models\DeliveryNote;
use App\Models\DocumentEmail;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Documents\DocumentSummary;
use App\Services\Pdf\DocumentPdfService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

const DOC_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

beforeEach(function (): void {
    $this->travelTo('2026-03-15 10:00:00');
    Storage::fake('local');

    $this->tenant = Tenant::create([
        'name' => 'FatturaFacile Italy SRL',
        'legal_name' => 'FatturaFacile Italy SRL',
        'vat_number' => '00811720580',
        'country' => 'IT',
        'default_currency' => 'EUR',
    ]);

    app(TenantContext::class)->setTenant($this->tenant);

    $this->user = User::create(['name' => 'Leonardo', 'email' => 'leo@fatturafacile.com', 'password' => bcrypt('secret123'), 'locale' => 'en']);
    $this->user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    Sanctum::actingAs($this->user);

    $this->contact = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Milan Customer SpA', 'country' => 'IT', 'email' => 'customer@example.com']);
    $this->invoice = Invoice::create([
        'tenant_id' => $this->tenant->id, 'contact_id' => $this->contact->id, 'invoice_type' => 'standard', 'invoice_number' => 'INV-2026-0001',
        'date' => '2026-03-01', 'currency' => 'EUR', 'total_net' => 100, 'total_tax' => 22, 'total_gross' => 122, 'jurisdiction' => 'IT',
    ]);
});

function docApi()
{
    return test()->withHeader('X-Tenant-ID', test()->tenant->id);
}

// ------------------------------------------------------------ Document emails

test('a document email is recorded, audited and queued to the contact address', function (): void {
    Queue::fake();

    $response = docApi()->postJson("/api/v1/documents/invoice/{$this->invoice->id}/email", ['message' => 'Thank you for your business'])->assertStatus(202);

    expect($response->json('data.recipient'))->toBe('customer@example.com')
        ->and($response->json('data.subject'))->toBe('Invoice INV-2026-0001')
        ->and($response->json('data.status'))->toBe('queued');
    Queue::assertPushed(SendDocumentEmailJob::class, 1);
    expect(DB::table('document_events')->where('eventable_id', $this->invoice->id)->where('event', 'email_queued')->count())->toBe(1);

    docApi()->postJson("/api/v1/documents/invoice/{$this->invoice->id}/email", ['to' => 'accounts@example.com'])->assertStatus(202)->assertJsonPath('data.recipient', 'accounts@example.com');
    expect(docApi()->getJson("/api/v1/documents/invoice/{$this->invoice->id}/emails")->json('data'))->toHaveCount(2);
});

test('emailing needs a recipient, a known document type and a valid address', function (): void {
    Queue::fake();
    $noEmail = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'No Email Customer', 'country' => 'IT']);
    $orphan = Invoice::create([
        'tenant_id' => $this->tenant->id, 'contact_id' => $noEmail->id, 'invoice_type' => 'standard', 'invoice_number' => 'INV-2026-0002',
        'date' => '2026-03-01', 'currency' => 'EUR', 'total_gross' => 10, 'jurisdiction' => 'IT',
    ]);

    docApi()->postJson("/api/v1/documents/invoice/{$orphan->id}/email")->assertUnprocessable()->assertJsonValidationErrors('to');
    docApi()->postJson("/api/v1/documents/invoice/{$this->invoice->id}/email", ['to' => 'not-an-email'])->assertUnprocessable();
    docApi()->postJson("/api/v1/documents/spaceship/{$this->invoice->id}/email")->assertNotFound();
    docApi()->postJson('/api/v1/documents/invoice/'.Str::uuid().'/email')->assertNotFound();

    Queue::assertNothingPushed();
});

test('the email job sends a translated summary mail and marks the email as sent', function (): void {
    Mail::fake();
    $note = DeliveryNote::create(['tenant_id' => $this->tenant->id, 'contact_id' => $this->contact->id, 'ddt_number' => 'DDT-2026-0001', 'date' => '2026-03-02']);

    $invoiceEmail = DocumentEmail::create([
        'tenant_id' => $this->tenant->id, 'emailable_type' => $this->invoice->getMorphClass(), 'emailable_id' => $this->invoice->id,
        'recipient' => 'customer@example.com', 'subject' => 'Invoice INV-2026-0001', 'message' => 'Thank you', 'status' => 'queued',
    ]);
    $noteEmail = DocumentEmail::create([
        'tenant_id' => $this->tenant->id, 'emailable_type' => $note->getMorphClass(), 'emailable_id' => $note->id,
        'recipient' => 'customer@example.com', 'subject' => 'Delivery note DDT-2026-0001', 'status' => 'queued',
    ]);

    (new SendDocumentEmailJob($invoiceEmail->id))->handle(app(DocumentSummary::class), app(DocumentPdfService::class));
    (new SendDocumentEmailJob($noteEmail->id))->handle(app(DocumentSummary::class), app(DocumentPdfService::class));

    Mail::assertSent(DocumentMail::class, 2);
    expect($invoiceEmail->refresh()->status)->toBe('sent')->and($noteEmail->refresh()->status)->toBe('sent');

    $mail = new DocumentMail($invoiceEmail, app(DocumentSummary::class)->summarize($this->invoice->load('contact')));
    $mail->assertSeeInHtml('Dear Milan Customer SpA,')
        ->assertSeeInHtml('Here are the details of invoice INV-2026-0001.')
        ->assertSeeInHtml('Total: 122.00 EUR')
        ->assertSeeInHtml('Thank you');

    $noteMail = new DocumentMail($noteEmail, app(DocumentSummary::class)->summarize($note->load('contact')));
    $noteMail->assertDontSeeInHtml('Total:');
});

test('signing a work report emails it when the tenant enabled auto email', function (): void {
    Queue::fake();
    docApi()->putJson('/api/v1/settings/preferences/work_orders', ['auto_email' => true])->assertOk();

    $id = docApi()->postJson('/api/v1/field-service/work-orders', [
        'client_id' => $this->contact->id, 'date' => '2026-03-14',
        'lines' => [['type' => 'labor', 'description' => 'Repair', 'quantity' => 1, 'unit_price' => 80, 'tax_rate' => 22]],
    ])->json('data.id');

    docApi()->postJson("/api/v1/field-service/work-orders/{$id}/signature", ['signed_by' => 'Mario Rossi', 'signature_base64' => DOC_PNG])->assertOk();

    Queue::assertPushed(SendDocumentEmailJob::class, 1);
    expect(DocumentEmail::first()->subject)->toStartWith('Work report WO-2026-0001');
});

// ------------------------------------------------------------ Attachments

test('files are stored privately, listed without their path, downloaded and deleted', function (): void {
    $upload = docApi()->postJson('/api/v1/attachments', [
        'attachable_type' => 'contact', 'attachable_id' => $this->contact->id,
        'file' => UploadedFile::fake()->createWithContent('Contract 2026.txt', 'hello world'),
    ])->assertCreated();

    $id = $upload->json('data.id');
    expect($upload->json('data.original_name'))->toBe('Contract 2026.txt')
        ->and($upload->json('data'))->not->toHaveKey('path')
        ->and((int) $upload->json('data.size'))->toBe(11);

    $path = DB::table('attachments')->where('id', $id)->value('path');
    expect($path)->toStartWith("tenants/{$this->tenant->id}/attachments/");
    Storage::disk('local')->assertExists($path);

    $list = docApi()->getJson("/api/v1/attachments?attachable_type=contact&attachable_id={$this->contact->id}")->assertOk();
    expect($list->json('data'))->toHaveCount(1);

    $download = docApi()->get("/api/v1/attachments/{$id}/download")->assertOk();
    expect($download->streamedContent())->toBe('hello world');

    docApi()->deleteJson("/api/v1/attachments/{$id}")->assertOk();
    Storage::disk('local')->assertMissing($path);
    docApi()->getJson("/api/v1/attachments/{$id}/download")->assertNotFound();
});

test('attachments reject dangerous file types, unknown targets and records of other tenants', function (): void {
    $other = Tenant::create(['name' => 'Other SRL', 'legal_name' => 'Other SRL', 'country' => 'IT', 'default_currency' => 'EUR']);
    app(TenantContext::class)->setTenant($other);
    $foreign = Contact::create(['tenant_id' => $other->id, 'company_name' => 'Foreign Customer', 'country' => 'IT']);
    app(TenantContext::class)->setTenant($this->tenant);

    $post = fn (array $override) => docApi()->postJson('/api/v1/attachments', array_merge([
        'attachable_type' => 'contact', 'attachable_id' => $this->contact->id,
        'file' => UploadedFile::fake()->createWithContent('notes.txt', 'ok'),
    ], $override));

    $post(['file' => UploadedFile::fake()->create('malware.exe', 10, 'application/octet-stream')])->assertUnprocessable()->assertJsonValidationErrors('file');
    $post(['attachable_type' => 'spaceship'])->assertUnprocessable()->assertJsonValidationErrors('attachable_type');
    $post(['attachable_id' => $foreign->id])->assertNotFound();
    docApi()->getJson('/api/v1/attachments')->assertUnprocessable();

    expect(DB::table('attachments')->count())->toBe(0);
});
