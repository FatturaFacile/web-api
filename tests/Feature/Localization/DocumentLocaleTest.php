<?php

declare(strict_types=1);

use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Models\PaymentReminder;
use App\Models\PurchaseOrder;
use App\Models\Quote;
use App\Services\Pdf\DocumentPdfService;
use App\Services\Pdf\RenderedPdf;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use Modules\Invoicing\Models\SalesDeliveryNote;
use Modules\Platform\Localization\Infrastructure\Eloquent\FreezesDocumentLocale;

require_once __DIR__.'/LocalizationSupport.php';

beforeEach(function (): void {
    locSetUp($this);
});

function locQuote(object $test, ?string $contactLanguage, array $extra = []): Quote
{
    $contact = locContact($test->tenant, $contactLanguage);

    return locInTenant($test->tenant, fn (): Quote => Quote::create(['tenant_id' => $test->tenant->id, 'contact_id' => $contact->id, 'quote_number' => 'Q-'.Str::random(5), 'date' => '2026-03-01'] + $extra));
}

function locInvoice(object $test, ?string $contactLanguage, array $extra = []): Invoice
{
    $contact = locContact($test->tenant, $contactLanguage);

    return locInTenant($test->tenant, fn (): Invoice => Invoice::create(['tenant_id' => $test->tenant->id, 'contact_id' => $contact->id, 'invoice_number' => 'F-'.Str::random(5), 'date' => '2026-03-01'] + $extra));
}

it('freezes the document language of the contact on a new document', function (): void {
    expect(locQuote($this, 'de')->locale)->toBe('de-CH')
        ->and(locQuote($this, 'fr-CH')->locale)->toBe('fr-CH')
        ->and(locInvoice($this, 'de')->locale)->toBe('de-CH');
});

it('uses the default locale of the issuing company when the contact states no language', function (): void {
    expect(locQuote($this, null)->locale)->toBe('it-CH');

    $contact = locContact($this->otherTenant, null);
    $italian = locInTenant($this->otherTenant, fn (): Quote => Quote::create(['tenant_id' => $this->otherTenant->id, 'contact_id' => $contact->id, 'quote_number' => 'Q-IT', 'date' => '2026-03-01']));

    expect($italian->locale)->toBe('it-IT');
});

it('keeps a locale chosen at creation', function (): void {
    expect(locQuote($this, 'de', ['locale' => 'en'])->locale)->toBe('en');
});

it('lets a draft change its print language', function (): void {
    $quote = locQuote($this, 'de');

    $quote->update(['locale' => 'fr-CH']);

    expect($quote->fresh()->locale)->toBe('fr-CH');
});

it('refuses to change the print language once the document is issued', function (): void {
    $quote = locQuote($this, 'de');
    $quote->update(['status' => 'sent']);

    expect(fn () => $quote->fresh()->update(['locale' => 'fr-CH']))->toThrow(ValidationException::class)
        ->and($quote->fresh()->locale)->toBe('de-CH');

    // Other changes of an issued document are not affected by the freeze
    $quote->fresh()->update(['notes' => 'ok']);
    expect($quote->fresh()->locale)->toBe('de-CH');
});

it('reads and changes the locale of a draft through the API and refuses an issued document', function (): void {
    $quote = locQuote($this, 'de');

    $this->getJson("/api/v1/documents/quote/{$quote->id}/locale")->assertOk()
        ->assertJsonPath('data.locale', 'de-CH')
        ->assertJsonPath('data.is_editable', true)
        ->assertJsonPath('data.type', 'quote');

    $this->putJson("/api/v1/documents/quote/{$quote->id}/locale", ['locale' => 'en'])->assertOk()->assertJsonPath('data.locale', 'en');

    $quote->fresh()->update(['status' => 'sent']);

    $this->putJson("/api/v1/documents/quote/{$quote->id}/locale", ['locale' => 'fr-CH'])->assertStatus(422)->assertJsonValidationErrors('locale');
    $this->getJson("/api/v1/documents/quote/{$quote->id}/locale")->assertOk()->assertJsonPath('data.locale', 'en')->assertJsonPath('data.is_editable', false);
});

it('validates the locale of a document and the document type', function (): void {
    $quote = locQuote($this, null);

    $this->putJson("/api/v1/documents/quote/{$quote->id}/locale", ['locale' => 'es-ES'])->assertStatus(422)->assertJsonValidationErrors('locale');
    $this->getJson("/api/v1/documents/ticket/{$quote->id}/locale")->assertNotFound();
});

it('hides the documents of another tenant', function (): void {
    $contact = locContact($this->otherTenant, null);
    $foreign = locInTenant($this->otherTenant, fn (): Quote => Quote::create(['tenant_id' => $this->otherTenant->id, 'contact_id' => $contact->id, 'quote_number' => 'Q-X', 'date' => '2026-03-01']));

    $this->getJson("/api/v1/documents/quote/{$foreign->id}/locale")->assertNotFound();
});

it('exposes the frozen locale of an invoice and accepts it at creation and while it is a draft', function (): void {
    $invoice = locInvoice($this, 'de');

    expect($invoice->locale)->toBe('de-CH');

    $this->getJson("/api/v1/documents/invoice/{$invoice->id}/locale")->assertOk()->assertJsonPath('data.locale', 'de-CH');
    $this->putJson("/api/v1/documents/invoice/{$invoice->id}/locale", ['locale' => 'it-CH'])->assertOk();

    $invoice->fresh()->update(['status' => 'approved']);

    $this->putJson("/api/v1/documents/invoice/{$invoice->id}/locale", ['locale' => 'fr-CH'])->assertStatus(422);
    expect(Invoice::query()->find($invoice->id)?->locale)->toBe('it-CH');
});

it('freezes the locale of every sales and purchase document model', function (): void {
    foreach ([Invoice::class, Quote::class, SalesDeliveryNote::class, DeliveryNote::class, PaymentReminder::class, PurchaseOrder::class] as $model) {
        expect(class_uses_recursive($model))->toContain(FreezesDocumentLocale::class);
    }

    expect((new PaymentReminder)->documentDraftStatuses())->toContain('queued')
        ->and((new PurchaseOrder(['supplier_id' => 'abc']))->documentContactId())->toBe('abc')
        ->and((new Quote)->documentDraftStatuses())->toBe(['draft']);
});

it('has a locale column on every document table', function (): void {
    foreach (['invoices', 'quotes', 'delivery_notes', 'payment_reminders', 'purchase_orders'] as $table) {
        expect(Schema::hasColumn($table, 'locale'))->toBeTrue($table);
    }
});

it('refuses the Italian translation print for a company that is not Italian', function (): void {
    $invoice = locInvoice($this, 'de');
    $invoice->update(['status' => 'approved']);

    $this->getJson("/api/v1/sales/invoices/{$invoice->id}/italian-translation-print")->assertStatus(422)
        ->assertJsonPath('status', 422);
});

it('refuses the Italian translation print for an invoice that is not issued or already Italian', function (): void {
    locActor($this, 'owner', $this->otherTenant);

    $contact = locContact($this->otherTenant, 'it');
    $italian = locInTenant($this->otherTenant, fn (): Invoice => Invoice::create(['tenant_id' => $this->otherTenant->id, 'contact_id' => $contact->id, 'invoice_number' => 'F-IT', 'date' => '2026-03-01']));
    $italian->update(['status' => 'approved']);

    $this->getJson("/api/v1/sales/invoices/{$italian->id}/italian-translation-print")->assertStatus(422);

    $foreignContact = locContact($this->otherTenant, 'de');
    $draft = locInTenant($this->otherTenant, fn (): Invoice => Invoice::create(['tenant_id' => $this->otherTenant->id, 'contact_id' => $foreignContact->id, 'invoice_number' => 'F-DE', 'date' => '2026-03-01']));

    $this->getJson("/api/v1/sales/invoices/{$draft->id}/italian-translation-print")->assertStatus(422);
});

it('prints the Italian translation of an issued foreign-language invoice of an Italian company', function (): void {
    locActor($this, 'owner', $this->otherTenant);

    $contact = locContact($this->otherTenant, 'de');
    $invoice = locInTenant($this->otherTenant, fn (): Invoice => Invoice::create(['tenant_id' => $this->otherTenant->id, 'contact_id' => $contact->id, 'invoice_number' => 'F-DE-1', 'date' => '2026-03-01']));
    $invoice->update(['status' => 'approved']);

    $this->mock(DocumentPdfService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('render')->once()->withArgs(fn (Invoice $document, array $options): bool => $options === ['locale' => 'it'])
            ->andReturn(new RenderedPdf('%PDF-1.4 fake', 'Invoice-F-DE-1.pdf'));
    });

    $this->get("/api/v1/sales/invoices/{$invoice->id}/italian-translation-print")->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Content-Disposition', 'attachment; filename="IT-Invoice-F-DE-1.pdf"');
});
