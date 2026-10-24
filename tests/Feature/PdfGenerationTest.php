<?php

declare(strict_types=1);

use App\Contracts\PdfGenerator;
use App\Jobs\SendDocumentEmailJob;
use App\Jobs\SendPaymentReminderEmail;
use App\Mail\DocumentMail;
use App\Mail\PaymentReminderMail;
use App\Models\Contact;
use App\Models\DeliveryNote;
use App\Models\DocumentEmail;
use App\Models\Invoice;
use App\Models\PaymentReminder;
use App\Models\Quote;
use App\Models\SalesOrder;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Documents\DocumentSummary;
use App\Services\Pdf\DocumentPdfService;
use App\Services\SwissQrBill\SwissQrBillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

const PDF_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

beforeEach(function (): void {
    $this->travelTo('2026-03-15 10:00:00');
    Storage::fake('local');

    $this->tenant = Tenant::create([
        'name' => 'FatturaFacile Italy SRL',
        'legal_name' => 'Società Fattura Facile S.r.l.',
        'vat_number' => '00811720580',
        'fiscal_code' => '00811720580',
        'country' => 'IT',
        'default_currency' => 'EUR',
        'iban' => 'IT60X0542811101000000123456',
        'qr_iban' => 'CH4431999123000889012',
    ]);

    $this->user = User::create(['name' => 'Leonardo Technician', 'email' => 'leo@fatturafacile.com', 'password' => bcrypt('secret123'), 'locale' => 'en']);
    $this->user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    Sanctum::actingAs($this->user);

    $this->contact = Contact::create([
        'tenant_id' => $this->tenant->id, 'company_name' => 'Café Müller Zürich AG', 'country' => 'CH',
        'vat_number' => 'CHE-123.456.789', 'email' => 'customer@example.com',
    ]);
    $this->contact->addresses()->create(['type' => 'billing', 'street' => 'Bahnhofstrasse', 'house_number' => '12', 'postal_code' => '8001', 'city' => 'Zürich', 'state_canton' => 'ZH', 'country' => 'CH']);

    $this->invoice = Invoice::create([
        'tenant_id' => $this->tenant->id, 'contact_id' => $this->contact->id, 'invoice_type' => 'standard', 'invoice_number' => 'INV-2026-0001',
        'date' => '2026-03-01', 'due_date' => '2026-03-31', 'currency' => 'EUR', 'total_net' => 100, 'total_tax' => 22, 'total_gross' => 122,
        'total_paid' => 40, 'payment_status' => 'partially_paid', 'jurisdiction' => 'IT', 'payment_method' => 'MP05', 'bank_iban' => 'IT60X0542811101000000123456',
        'notes' => 'Thank you for choosing us',
    ]);
    $this->invoice->items()->create(['description' => 'Consulting — design review', 'quantity' => 2, 'unit_price' => 50, 'tax_rate' => 22, 'line_total' => 100]);
});

function pdfApi()
{
    return test()->withHeader('X-Tenant-ID', test()->tenant->id);
}

/**
 * A generator that records what it renders and returns a fixed payload, standing in for the PDF engine.
 */
function fakePdfGenerator(string $content = '%PDF-fake'): object
{
    return new class($content) implements PdfGenerator
    {
        /** @var array<int, array{view: string, data: array<string, mixed>}> */
        public array $calls = [];

        public function __construct(public string $content) {}

        public function render(string $view, array $data = []): string
        {
            $this->calls[] = ['view' => $view, 'data' => $data];

            return $this->content;
        }
    };
}

function renderedHtml(object $generator): string
{
    $call = $generator->calls[array_key_last($generator->calls)];

    return view($call['view'], $call['data'])->render();
}

function makeQuote(): Quote
{
    $quote = Quote::create([
        'tenant_id' => test()->tenant->id, 'contact_id' => test()->contact->id, 'quote_number' => 'QT-2026-0001', 'date' => '2026-03-02',
        'valid_until' => '2026-04-02', 'currency' => 'EUR', 'total_net' => 200, 'total_tax' => 44, 'total_gross' => 244, 'custom_text' => 'Prices valid for 30 days',
    ]);
    $quote->items()->create(['description' => 'Hosting setup', 'quantity' => 1, 'unit_price' => 200, 'tax_rate' => 22, 'line_total' => 200]);

    return $quote;
}

function makeDeliveryNote(bool $showPrices, string $number = 'DDT-2026-0001'): DeliveryNote
{
    $note = DeliveryNote::create([
        'tenant_id' => test()->tenant->id, 'contact_id' => test()->contact->id, 'ddt_number' => $number, 'date' => '2026-03-03',
        'transport_reason' => 'Sale', 'goods_appearance' => 'Boxes', 'packages_count' => 3, 'gross_weight_kg' => 12.5, 'carrier_name' => 'Fast Carrier',
        'shipping_address' => 'Warehouse 7, Lugano', 'show_prices' => $showPrices,
    ]);
    $note->items()->create(['description' => 'Widget', 'quantity' => 5, 'unit_price' => 10, 'tax_rate' => 22]);

    return $note;
}

function makeWorkOrder(): WorkOrder
{
    $workOrder = WorkOrder::create([
        'tenant_id' => test()->tenant->id, 'client_id' => test()->contact->id, 'technician_user_id' => test()->user->id, 'work_order_number' => 'WO-2026-0001',
        'date' => '2026-03-04', 'labor_hours' => 2.5, 'intervention_address' => 'Via Roma 1, Milan', 'status' => 'signed', 'client_signed_by' => 'Mario Rossi',
        'signed_at' => '2026-03-04 12:00:00', 'client_signature_path' => 'tenants/x/signatures/wo.png',
    ]);
    Storage::disk('local')->put('tenants/x/signatures/wo.png', base64_decode(PDF_PNG));
    $workOrder->lines()->create(['type' => 'labor', 'description' => 'On-site repair', 'quantity' => 2.5, 'unit_price' => 80, 'tax_rate' => 22, 'line_total' => 200]);
    $workOrder->lines()->create(['type' => 'material', 'description' => 'Replacement pump', 'quantity' => 1, 'unit_price' => 150, 'tax_rate' => 22, 'line_total' => 150]);

    return $workOrder;
}

// ------------------------------------------------------------ Real engine output

test('every core document type downloads as a real PDF with UTF-8 content', function (): void {
    $quote = makeQuote();
    $order = SalesOrder::create(['tenant_id' => $this->tenant->id, 'contact_id' => $this->contact->id, 'order_number' => 'SO-2026-0001', 'date' => '2026-03-02', 'currency' => 'EUR']);
    $order->items()->create(['description' => 'Widget', 'quantity' => 1, 'unit_price' => 10, 'tax_rate' => 22, 'line_total' => 10]);
    $documents = [
        ['invoice', $this->invoice->id, 'Invoice-INV-2026-0001.pdf'],
        ['quote', $quote->id, 'Quote-QT-2026-0001.pdf'],
        ['sales_order', $order->id, 'SalesOrder-SO-2026-0001.pdf'],
        ['delivery_note', makeDeliveryNote(false)->id, 'DeliveryNote-DDT-2026-0001.pdf'],
        ['work_order', makeWorkOrder()->id, 'WorkOrder-WO-2026-0001.pdf'],
    ];

    foreach ($documents as [$type, $id, $filename]) {
        $response = pdfApi()->get("/api/v1/documents/{$type}/{$id}/pdf")->assertOk();

        expect($response->headers->get('Content-Type'))->toBe('application/pdf')
            ->and($response->headers->get('Content-Disposition'))->toBe("attachment; filename=\"{$filename}\"")
            ->and(substr($response->getContent(), 0, 5))->toBe('%PDF-')
            ->and(strlen($response->getContent()))->toBeGreaterThan(1000);
    }

    expect(pdfApi()->get("/api/v1/documents/invoice/{$this->invoice->id}/pdf?inline=1")->headers->get('Content-Disposition'))
        ->toBe('inline; filename="Invoice-INV-2026-0001.pdf"');
});

test('a QR-bill invoice renders as a real PDF too', function (): void {
    $qr = Invoice::create([
        'tenant_id' => $this->tenant->id, 'contact_id' => $this->contact->id, 'invoice_type' => 'ch_qr_bill', 'invoice_number' => 'CH-2026-0001', 'date' => '2026-03-01',
        'currency' => 'CHF', 'total_net' => 100, 'total_tax' => 8.1, 'total_gross' => 108.1, 'jurisdiction' => 'CH',
    ]);
    $qr->items()->create(['description' => 'Service', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 8.1, 'line_total' => 100]);

    $response = pdfApi()->get("/api/v1/documents/invoice/{$qr->id}/pdf")->assertOk();

    expect(substr($response->getContent(), 0, 5))->toBe('%PDF-');
});

test('unknown types, unknown documents and other tenants documents are not found', function (): void {
    $other = Tenant::create(['name' => 'Other SRL', 'legal_name' => 'Other SRL', 'country' => 'IT', 'default_currency' => 'EUR']);
    $foreign = Contact::create(['tenant_id' => $other->id, 'company_name' => 'Foreign', 'country' => 'IT']);
    $foreignInvoice = Invoice::create(['tenant_id' => $other->id, 'contact_id' => $foreign->id, 'invoice_type' => 'standard', 'invoice_number' => 'X-1', 'date' => '2026-03-01', 'currency' => 'EUR', 'jurisdiction' => 'IT']);

    pdfApi()->get("/api/v1/documents/spaceship/{$this->invoice->id}/pdf")->assertNotFound();
    pdfApi()->get('/api/v1/documents/invoice/'.Str::uuid().'/pdf')->assertNotFound();
    pdfApi()->get("/api/v1/documents/invoice/{$foreignInvoice->id}/pdf")->assertNotFound();
});

// ------------------------------------------------------------ Engine decoupling and template content

test('controllers depend on the PdfGenerator contract, not on dompdf', function (): void {
    $fake = fakePdfGenerator('FAKE-PDF-BYTES');
    $this->app->instance(PdfGenerator::class, $fake);

    $response = pdfApi()->get("/api/v1/documents/invoice/{$this->invoice->id}/pdf")->assertOk();

    expect($response->getContent())->toBe('FAKE-PDF-BYTES')
        ->and($fake->calls)->toHaveCount(1)
        ->and($fake->calls[0]['view'])->toBe('documents.invoice');
});

test('the invoice template shows issuer, customer, lines, totals and payment details in English', function (): void {
    $fake = fakePdfGenerator();
    (new DocumentPdfService($fake, app(SwissQrBillService::class)))->render($this->invoice);
    $html = renderedHtml($fake);

    expect($html)->toContain('<meta charset="utf-8">')
        ->toContain('Società Fattura Facile S.r.l.')
        ->toContain('Café Müller Zürich AG')
        ->toContain('Bahnhofstrasse 12')
        ->toContain('8001 Zürich (ZH)')
        ->toContain('Invoice')
        ->toContain('INV-2026-0001')
        ->toContain('Consulting — design review')
        ->toContain('Net total')
        ->toContain('122.00 EUR')
        ->toContain('Balance due')
        ->toContain('82.00 EUR')
        ->toContain('Payment details')
        ->toContain('IT60X0542811101000000123456')
        ->toContain('Thank you for choosing us')
        ->not->toContain('Payment part');
});

test('credit notes and proformas get their own titles', function (): void {
    $fake = fakePdfGenerator();
    $service = new DocumentPdfService($fake, app(SwissQrBillService::class));

    foreach (['credit_note' => 'Credit note', 'proforma' => 'Proforma invoice'] as $type => $title) {
        $invoice = Invoice::create(['tenant_id' => $this->tenant->id, 'contact_id' => $this->contact->id, 'invoice_type' => $type, 'invoice_number' => strtoupper($type).'-1', 'date' => '2026-03-01', 'currency' => 'EUR', 'jurisdiction' => 'IT']);
        $rendered = $service->render($invoice);

        expect(renderedHtml($fake))->toContain($title)
            ->and($rendered->filename)->toBe(Str::studly($type).'-'.strtoupper($type).'-1.pdf');
    }
});

test('the quote template shows terms and an unsigned acceptance line, or the signature once signed', function (): void {
    $quote = makeQuote();
    $fake = fakePdfGenerator();
    $service = new DocumentPdfService($fake, app(SwissQrBillService::class));

    $service->render($quote);
    expect(renderedHtml($fake))->toContain('Quote')->toContain('QT-2026-0001')->toContain('Valid until')->toContain('Terms and conditions')
        ->toContain('Prices valid for 30 days')->toContain('Acceptance of the quote')->toContain('Date and signature');

    $quote->update(['signature_data' => PDF_PNG, 'signed_by' => 'Mario Rossi', 'signed_at' => '2026-03-05 09:00:00']);
    $service->render($quote->refresh());
    expect(renderedHtml($fake))->toContain('Signed by Mario Rossi')->toContain('data:image/png;base64,')->not->toContain('Date and signature');
});

test('the delivery note template hides prices unless the note shows them', function (): void {
    $fake = fakePdfGenerator();
    $service = new DocumentPdfService($fake, app(SwissQrBillService::class));

    $service->render(makeDeliveryNote(false));
    expect(renderedHtml($fake))->toContain('Delivery note')->toContain('Warehouse 7, Lugano')->toContain('Fast Carrier')->toContain('Widget')
        ->not->toContain('Unit price')->not->toContain('Net total');

    $service->render(makeDeliveryNote(true, 'DDT-2026-0002'));
    expect(renderedHtml($fake))->toContain('Unit price')->toContain('Net total')->toContain('50.00');
});

test('the work report template lists technician, times, typed lines and the stored signature', function (): void {
    $fake = fakePdfGenerator();
    (new DocumentPdfService($fake, app(SwissQrBillService::class)))->render(makeWorkOrder());
    $html = renderedHtml($fake);

    expect($html)->toContain('Work report')->toContain('WO-2026-0001')->toContain('Leonardo Technician')->toContain('Via Roma 1, Milan')
        ->toContain('Labor')->toContain('Material')->toContain('On-site repair')->toContain('Signed by Mario Rossi')->toContain('data:image/png;base64,');
});

// ------------------------------------------------------------ Email attachments

test('document emails attach the generated PDF and record its file name', function (): void {
    Mail::fake();
    $this->app->instance(PdfGenerator::class, fakePdfGenerator('%PDF-fake'));

    $email = DocumentEmail::create([
        'tenant_id' => $this->tenant->id, 'emailable_type' => $this->invoice->getMorphClass(), 'emailable_id' => $this->invoice->id,
        'recipient' => 'customer@example.com', 'subject' => 'Invoice INV-2026-0001', 'status' => 'queued',
    ]);

    (new SendDocumentEmailJob($email->id))->handle(app(DocumentSummary::class), app(DocumentPdfService::class));

    Mail::assertSent(DocumentMail::class, fn (DocumentMail $mail): bool => $mail->pdf?->content === '%PDF-fake' && $mail->pdf->filename === 'Invoice-INV-2026-0001.pdf' && count($mail->attachments()) === 1);
    expect($email->refresh()->status)->toBe('sent')->and($email->attachment_name)->toBe('Invoice-INV-2026-0001.pdf');
});

test('the attached document is a genuine PDF when the real engine is used', function (): void {
    Mail::fake();
    $quote = makeQuote();
    $email = DocumentEmail::create([
        'tenant_id' => $this->tenant->id, 'emailable_type' => $quote->getMorphClass(), 'emailable_id' => $quote->id,
        'recipient' => 'customer@example.com', 'subject' => 'Quote QT-2026-0001', 'status' => 'queued',
    ]);

    (new SendDocumentEmailJob($email->id))->handle(app(DocumentSummary::class), app(DocumentPdfService::class));

    $sent = Mail::sent(DocumentMail::class)->first();
    expect($sent->pdf->filename)->toBe('Quote-QT-2026-0001.pdf')
        ->and(substr($sent->pdf->content, 0, 5))->toBe('%PDF-')
        ->and($sent->attachments())->toHaveCount(1);
});

test('a PDF rendering failure marks the document email as failed instead of sending it bare', function (): void {
    Mail::fake();
    $this->app->instance(PdfGenerator::class, new class implements PdfGenerator
    {
        public function render(string $view, array $data = []): string
        {
            throw new RuntimeException('Rendering engine crashed');
        }
    });

    $email = DocumentEmail::create([
        'tenant_id' => $this->tenant->id, 'emailable_type' => $this->invoice->getMorphClass(), 'emailable_id' => $this->invoice->id,
        'recipient' => 'customer@example.com', 'subject' => 'Invoice INV-2026-0001', 'status' => 'queued',
    ]);

    (new SendDocumentEmailJob($email->id))->handle(app(DocumentSummary::class), app(DocumentPdfService::class));

    Mail::assertNothingSent();
    expect($email->refresh()->status)->toBe('failed')->and($email->error)->toBe('Rendering engine crashed');
});

test('payment reminders attach the invoice PDF but are still sent when it cannot be rendered', function (): void {
    Mail::fake();
    $this->app->instance(PdfGenerator::class, fakePdfGenerator('%PDF-fake'));
    $reminder = PaymentReminder::create(['tenant_id' => $this->tenant->id, 'invoice_id' => $this->invoice->id, 'level' => 1, 'recipient' => 'customer@example.com', 'amount_due' => 82]);

    (new SendPaymentReminderEmail($reminder->id))->handle(app(DocumentPdfService::class));

    Mail::assertSent(PaymentReminderMail::class, fn (PaymentReminderMail $mail): bool => $mail->pdf?->content === '%PDF-fake' && $mail->pdf->filename === 'Invoice-INV-2026-0001.pdf' && count($mail->attachments()) === 1);

    $this->app->instance(PdfGenerator::class, new class implements PdfGenerator
    {
        public function render(string $view, array $data = []): string
        {
            throw new RuntimeException('Rendering engine crashed');
        }
    });
    $second = PaymentReminder::create(['tenant_id' => $this->tenant->id, 'invoice_id' => $this->invoice->id, 'level' => 2, 'recipient' => 'customer@example.com', 'amount_due' => 82]);

    (new SendPaymentReminderEmail($second->id))->handle(app(DocumentPdfService::class));

    expect($second->refresh()->status)->toBe('sent')
        ->and(Mail::sent(PaymentReminderMail::class)->last()->pdf)->toBeNull();
});
