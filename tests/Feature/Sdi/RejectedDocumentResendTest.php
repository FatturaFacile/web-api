<?php

declare(strict_types=1);

use App\Actions\Sdi\SendInvoiceToSdi;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\SDI\Enums\FpaStatus;
use Modules\SDI\Models\FpaDocument;
use Modules\SDI\Services\RejectedDocumentResubmissionService;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create([
        'name' => 'Rossi Srl', 'legal_name' => 'Rossi Srl', 'vat_number' => '00811720580', 'fiscal_code' => '00811720580', 'country' => 'IT', 'default_currency' => 'EUR',
        'iban' => 'IT60X0542811101000000123456', 'pec_email' => 'rossi@pec.example.it',
        'settings' => ['street' => 'Via Verdi 1', 'postal_code' => '20121', 'city' => 'Milano', 'province' => 'MI'],
    ]);
    $this->contact = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Bianchi SpA', 'country' => 'IT', 'vat_number' => '01234567897', 'sdi_code' => '0000000', 'pec' => 'bianchi@pec.example.it']);
    $this->contact->addresses()->create(['tenant_id' => $this->tenant->id, 'type' => 'billing', 'street' => 'Via Dante 2', 'postal_code' => '00100', 'city' => 'Roma', 'country' => 'IT']);
    app(TenantContext::class)->setTenant($this->tenant);
    $this->tenant->update(['sdi_channel' => 'openapi', 'sdi_api_credentials' => ['token' => 'tok']]);
});

afterEach(fn () => app(TenantContext::class)->clear());

function rejectedInvoice(): array
{
    $invoice = Invoice::create([
        'tenant_id' => test()->tenant->id, 'contact_id' => test()->contact->id, 'invoice_type' => 'sdi_electronic', 'invoice_number' => 'INV-2026-0001',
        'date' => '2026-03-01', 'due_date' => '2026-03-31', 'currency' => 'EUR', 'total_net' => 100, 'total_tax' => 22, 'total_gross' => 122, 'jurisdiction' => 'IT',
    ]);
    $invoice->items()->create(['description' => 'Consulting', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 22, 'line_total' => 100]);

    Http::fake(['*' => Http::sequence()->push(['data' => ['id' => 'first', 'state' => 'NEW']], 200)->push(['data' => ['id' => 'second', 'state' => 'NEW']], 200)]);
    app(SendInvoiceToSdi::class)->execute($invoice);

    $document = FpaDocument::withoutGlobalScopes()->where('invoice_id', $invoice->id)->firstOrFail();
    $document->forceFill(['status' => FpaStatus::Rejected, 'resubmission_deadline_at' => now()->addDays(5)])->save();
    $invoice->sdiMetadata()->update(['sdi_status' => 'rejected']);

    return [$invoice, $document];
}

test('a rejected document is sent again with a new progressive and linked to the rejected one', function (): void {
    [$invoice, $rejected] = rejectedInvoice();

    $revision = app(RejectedDocumentResubmissionService::class)->resubmit($rejected);

    expect($revision->parent_fpa_document_id)->toBe($rejected->id)
        ->and($revision->id)->not->toBe($rejected->id)
        ->and($revision->file_name)->not->toBe($rejected->file_name)
        ->and($revision->status)->toBe(FpaStatus::Transmitted)
        ->and($revision->document_number)->toBe($rejected->document_number)
        ->and((int) base_convert((string) $revision->transmission_progressive, 36, 10))->toBeGreaterThan((int) base_convert((string) $rejected->transmission_progressive, 36, 10))
        ->and($invoice->refresh()->sdiMetadata->sdi_status)->toBe('sending');
});

test('the resubmission is refused after the five days and nothing is sent', function (): void {
    [, $rejected] = rejectedInvoice();
    $rejected->forceFill(['resubmission_deadline_at' => now()->subMinute()])->save();
    $before = FpaDocument::withoutGlobalScopes()->count();

    expect(fn () => app(RejectedDocumentResubmissionService::class)->resubmit($rejected->refresh()))->toThrow(DomainException::class, 'deadline');
    expect(FpaDocument::withoutGlobalScopes()->count())->toBe($before);
});

test('only a rejected document can be sent again', function (): void {
    [, $document] = rejectedInvoice();
    $document->forceFill(['status' => FpaStatus::Delivered])->save();

    expect(fn () => app(RejectedDocumentResubmissionService::class)->resubmit($document->refresh()))->toThrow(DomainException::class, 'rejected');
});
