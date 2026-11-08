<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\PaymentTerm;
use App\Models\SupplierPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Treasury\Models\PaymentBatch;
use Modules\Treasury\Models\PaymentProposal;

require_once __DIR__.'/TreasurySupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    treasurySetUp($this);
    $this->bank = treasuryBank($this->tenant, 'IT60X0542811101000000123456', $this->accounts['1001']);
    $term = PaymentTerm::query()->create(['tenant_id' => $this->tenant->id, 'code' => 'RD2-10', 'name' => '2% 10 days, net 60', 'days' => 60, 'discount_percent' => '2.00', 'discount_days' => 10]);

    $this->supplier = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Fornitore Alfa SRL', 'email' => 'alfa@example.com', 'type' => 'supplier', 'country' => 'IT', 'iban' => 'IT42 L123 4512 3451 2345 6789 012']);
    $this->discountSupplier = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Fornitore Sconto SPA', 'email' => 'sconto@example.com', 'type' => 'supplier', 'country' => 'IT', 'iban' => 'IT84S0542811101000000654321', 'payment_term_id' => $term->id]);
    $this->noIbanSupplier = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Senza IBAN SRL', 'email' => 'noiban@example.com', 'type' => 'supplier', 'country' => 'IT']);

    $this->due = treasurySupplierInvoice($this->tenant, $this->supplier, 'A-DUE', '1000.00');
    $this->discounted = treasurySupplierInvoice($this->tenant, $this->discountSupplier, 'B-DISC', '500.00', ['date' => now()->subDays(3)->toDateString(), 'due_date' => now()->addDays(57)->toDateString()]);
    treasurySupplierInvoice($this->tenant, $this->supplier, 'C-LATER', '300.00', ['due_date' => now()->addDays(60)->toDateString()]);
    treasurySupplierInvoice($this->tenant, $this->noIbanSupplier, 'D-NOIBAN', '200.00');
    // Waiting for approval: the approval flow flags new invoices itself, so the status is set afterwards.
    treasurySupplierInvoice($this->tenant, $this->supplier, 'E-BLOCKED', '150.00')->forceFill(['approval_status' => 'pending'])->saveQuietly();
    treasurySupplierInvoice($this->tenant, $this->supplier, 'F-CHF', '80.00', ['currency' => 'CHF']);
    treasurySupplierInvoice($this->otherTenant, treasuryInTenant($this->otherTenant, fn () => Contact::create(['tenant_id' => $this->otherTenant->id, 'company_name' => 'Altro', 'email' => 'x@example.com', 'type' => 'supplier', 'country' => 'IT', 'iban' => 'IT60X0542811101000000123456'])), 'Z-FOREIGN', '999.00');
});

function proposalPayload(object $test, array $overrides = []): array
{
    return array_merge(['bank_account_id' => $test->bank->id, 'execution_date' => now()->toDateString(), 'due_until' => now()->addDays(10)->toDateString(), 'format' => 'sepa_pain_001_it'], $overrides);
}

test('a proposal selects due invoices and available discounts, and lists suppliers without IBAN', function (): void {
    $response = $this->postJson('/api/v1/treasury/payment-proposals', proposalPayload($this))->assertCreated();
    $lines = collect($response->json('data.lines'))->keyBy('passive_invoice_id');

    expect($response->json('data.status'))->toBe('draft')
        ->and($response->json('data.item_count'))->toBe(2)
        ->and($response->json('data.total_amount'))->toBe('1490.00')
        ->and($response->json('data.total_discount'))->toBe('10.00')
        ->and($lines->keys()->sort()->values()->all())->toBe(collect([$this->due->id, $this->discounted->id])->sort()->values()->all())
        ->and($lines[$this->due->id])->toMatchArray(['selection_reason' => 'due', 'open_amount' => '1000.00', 'discount_amount' => '0.00', 'amount' => '1000.00'])
        ->and($lines[$this->discounted->id])->toMatchArray(['selection_reason' => 'discount', 'open_amount' => '500.00', 'discount_amount' => '10.00', 'amount' => '490.00', 'discount_deadline' => now()->addDays(7)->toDateString()])
        ->and($response->json('meta.excluded'))->toBe([['invoice_id' => $response->json('meta.excluded.0.invoice_id'), 'invoice_number' => 'D-NOIBAN', 'reason' => 'supplier_without_iban']]);

    // The invoices of a draft proposal are not proposed twice; without discounts only due invoices remain.
    $this->postJson('/api/v1/treasury/payment-proposals', proposalPayload($this))->assertUnprocessable()->assertJsonValidationErrors(['due_until']);
    $this->getJson('/api/v1/treasury/payment-proposals?status=draft')->assertOk()->assertJsonCount(1, 'data');
});

test('approval enforces segregation of duties and produces an approved pain.001 batch; the bank outcome closes the discount', function (): void {
    $proposalId = $this->postJson('/api/v1/treasury/payment-proposals', proposalPayload($this))->assertCreated()->json('data.id');

    // The author cannot approve.
    $this->postJson("/api/v1/treasury/payment-proposals/{$proposalId}/approve")->assertUnprocessable()->assertJsonValidationErrors(['approver']);

    $approver = treasuryActor($this, 'admin');
    $approved = $this->postJson("/api/v1/treasury/payment-proposals/{$proposalId}/approve")->assertOk()
        ->assertJsonPath('data.status', 'converted')
        ->assertJsonPath('data.approved_by', $approver->id);
    $batch = PaymentBatch::query()->with('lines')->findOrFail($approved->json('data.payment_batch_id'));

    expect($batch->status)->toBe('approved')
        ->and((string) $batch->created_by)->toBe((string) $this->accountant->id)
        ->and($batch->format)->toBe('sepa_pain_001_it')
        ->and($batch->lines->pluck('amount')->map(fn ($amount): string => bcadd((string) $amount, '0', 2))->sort()->values()->all())->toBe(['490.00', '1000.00']);

    // A decided proposal cannot be decided again.
    $this->postJson("/api/v1/treasury/payment-proposals/{$proposalId}/approve")->assertUnprocessable();
    $this->postJson("/api/v1/treasury/payment-proposals/{$proposalId}/reject", ['reason' => 'late'])->assertUnprocessable();

    // The existing payment factory exports the file and records the bank outcome.
    $this->postJson("/api/v1/treasury/payment-batches/{$batch->id}/export")->assertOk()->assertJsonPath('status', 'exported');
    $xml = (string) $batch->refresh()->pain001_xml;
    expect($xml)->toContain('urn:iso:std:iso:20022:tech:xsd:pain.001.001.09')
        ->and($xml)->toContain('<CtrlSum>1490.00</CtrlSum>')
        ->and($xml)->toContain('IT84S0542811101000000654321');

    $this->postJson("/api/v1/treasury/payment-batches/{$batch->id}/confirm", ['bank_reference' => 'BANK-REF-1', 'value_date' => now()->toDateString()])->assertOk()->assertJsonPath('status', 'executed');

    expect($this->due->refresh()->payment_status)->toBe('paid')
        ->and($this->discounted->refresh()->payment_status)->toBe('paid')
        ->and(SupplierPayment::withoutGlobalScopes()->where('passive_invoice_id', $this->discounted->id)->pluck('amount', 'type')->map(fn ($amount): string => bcadd((string) $amount, '0', 2))->all())->toBe(['payment' => '490.00', 'adjustment' => '10.00'])
        ->and((string) $this->bank->refresh()->current_balance)->toBe('8510.00');
});

test('a proposal is refused when an invoice changed, can be rejected, and is validated and isolated', function (): void {
    $proposalId = $this->postJson('/api/v1/treasury/payment-proposals', proposalPayload($this, ['include_discounts' => false]))->assertCreated()->assertJsonPath('data.item_count', 1)->json('data.id');
    $this->due->update(['total_paid' => '100.00', 'payment_status' => 'partial']);

    treasuryActor($this, 'owner');
    $this->postJson("/api/v1/treasury/payment-proposals/{$proposalId}/approve")->assertUnprocessable()->assertJsonValidationErrors(['proposal']);
    $this->postJson("/api/v1/treasury/payment-proposals/{$proposalId}/reject", [])->assertUnprocessable()->assertJsonValidationErrors(['reason']);
    $this->postJson("/api/v1/treasury/payment-proposals/{$proposalId}/reject", ['reason' => 'Invoice partly paid'])->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.rejection_reason', 'Invoice partly paid');

    $this->postJson('/api/v1/treasury/payment-proposals', [])->assertUnprocessable()->assertJsonValidationErrors(['bank_account_id', 'execution_date', 'due_until', 'format']);
    $this->postJson('/api/v1/treasury/payment-proposals', proposalPayload($this, ['bank_account_id' => treasuryBank($this->otherTenant, 'IT80N0306909606100000063275', null)->id, 'format' => 'mt101', 'execution_date' => now()->subDay()->toDateString()]))
        ->assertUnprocessable()->assertJsonValidationErrors(['bank_account_id', 'format', 'execution_date']);

    $foreign = treasuryInTenant($this->otherTenant, fn () => PaymentProposal::withoutGlobalScopes()->create(['tenant_id' => $this->otherTenant->id, 'proposal_number' => 'PP-X', 'bank_account_id' => $this->bank->id, 'status' => 'draft', 'format' => 'sepa_pain_001_it', 'execution_date' => now()->toDateString(), 'due_until' => now()->toDateString(), 'currency' => 'EUR', 'created_by' => $this->accountant->id]));
    $this->getJson("/api/v1/treasury/payment-proposals/{$foreign->id}")->assertNotFound();
    $this->postJson("/api/v1/treasury/payment-proposals/{$foreign->id}/approve")->assertNotFound();
    $this->getJson("/api/v1/treasury/payment-proposals/{$proposalId}")->assertOk()->assertJsonCount(1, 'data.lines');
});

test('read-only roles see proposals but cannot prepare, approve or reject them', function (): void {
    $proposalId = $this->postJson('/api/v1/treasury/payment-proposals', proposalPayload($this))->assertCreated()->json('data.id');

    treasuryActor($this, 'accountant_readonly');
    $this->getJson('/api/v1/treasury/payment-proposals')->assertOk()->assertJsonCount(1, 'data');
    $this->postJson('/api/v1/treasury/payment-proposals', proposalPayload($this))->assertForbidden();
    $this->postJson("/api/v1/treasury/payment-proposals/{$proposalId}/approve")->assertForbidden();
    $this->postJson("/api/v1/treasury/payment-proposals/{$proposalId}/reject", ['reason' => 'x'])->assertForbidden();

    expect(PaymentProposal::query()->firstOrFail()->status)->toBe('draft');
});
