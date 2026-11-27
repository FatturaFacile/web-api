<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\ForeignVatRefund\Models\FiscalRepresentativeMandate;
use Modules\ForeignVatRefund\Models\ForeignVatLedgerEntry;
use Modules\ForeignVatRefund\Models\VatRefundClaim;
use Modules\ForeignVatRefund\Services\ForeignVatAccounts;

require_once __DIR__.'/ForeignVatSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
});

/**
 * Two German lines (190 + 95 = 285 euro recoverable) in different quarters of 2026.
 */
function fvGermanLines(object $test): void
{
    fvEntry($test, ['document_number' => 'DE-1', 'supplier_country_code' => 'DE', 'document_date' => '2026-02-10', 'foreign_vat_amount' => 190, 'net_amount' => 1000]);
    fvEntry($test, ['document_number' => 'DE-2', 'supplier_country_code' => 'DE', 'document_date' => '2026-05-10', 'foreign_vat_amount' => 95, 'net_amount' => 500]);
}

/**
 * A Swiss ESTV claim of an Italian company: 760 CHF of Swiss VAT (798 euro carried), the scan attached, the mandate and the certificate on file.
 */
function fvSwissClaim(object $test): VatRefundClaim
{
    $entry = fvEntry($test, ['document_number' => 'ZH-1', 'supplier_country_code' => 'CH', 'currency_code' => 'CHF', 'net_amount' => 20000, 'foreign_vat_rate' => 3.8, 'foreign_vat_amount' => 760, 'exchange_rate_to_base' => 1.05]);
    $test->post(FVR_API.'/ledger/'.$entry->id.'/attachment', ['file' => UploadedFile::fake()->create('zh-1.pdf', 20, 'application/pdf')])->assertOk()->assertJsonPath('data.has_attachment', true);
    $mandate = $test->postJson(FVR_API.'/mandates', ['country_code' => 'CH', 'representative_name' => 'Treuhand AG', 'representative_tax_id' => 'CHE-123.456.788 MWST', 'mandate_start_date' => '2026-01-01', 'certificate_expiry_date' => '2028-01-01'])->assertCreated();
    $test->post(FVR_API.'/mandates/'.$mandate->json('data.id').'/documents', ['file' => UploadedFile::fake()->create('cert.pdf', 20, 'application/pdf'), 'type' => 'certificate', 'certificate_expiry_date' => '2028-01-01'])->assertOk();

    return fvClaim($test, ['target_country_code' => 'CH']);
}

test('the preview shows the lines, the totals, the deadline and what still blocks the claim', function (): void {
    fvSetUp($this);
    fvGermanLines($this);

    $this->postJson(FVR_API.'/claims/preview', ['target_country_code' => 'DE', 'fiscal_year' => 2026, 'period_type' => 'ANNUAL'])->assertOk()
        ->assertJsonPath('data.items_count', 2)
        ->assertJsonPath('data.total_requested_amount', '285.00')
        ->assertJsonPath('data.regime_type', 'EU_DIR_2008_9_CE')
        ->assertJsonPath('data.statutory_deadline_date', '2027-09-30')
        ->assertJsonPath('data.blockers', []);

    $this->postJson(FVR_API.'/claims/preview', ['target_country_code' => 'DE', 'fiscal_year' => 2026, 'period_type' => 'QUARTERLY', 'quarter' => 1])->assertOk()
        ->assertJsonPath('data.items_count', 1)->assertJsonPath('data.total_requested_amount', '190.00')->assertJsonPath('data.blockers', ['below_threshold']);
    $this->postJson(FVR_API.'/claims/preview', ['target_country_code' => 'IT', 'fiscal_year' => 2026, 'period_type' => 'ANNUAL'])->assertOk()->assertJsonPath('data.blockers', ['domestic_vat']);
    $this->postJson(FVR_API.'/claims/preview', ['target_country_code' => 'JP', 'fiscal_year' => 2026, 'period_type' => 'ANNUAL'])->assertOk()->assertJsonPath('data.blockers', ['no_procedure']);
    $this->postJson(FVR_API.'/claims/preview', ['target_country_code' => 'FR', 'fiscal_year' => 2026, 'period_type' => 'ANNUAL'])->assertOk()->assertJsonPath('data.blockers', ['no_items']);
    $this->postJson(FVR_API.'/claims/preview', ['target_country_code' => 'CH', 'fiscal_year' => 2026, 'period_type' => 'QUARTERLY', 'quarter' => 1])->assertOk()
        ->assertJsonFragment(['blockers' => ['no_items', 'period_not_allowed']]);
    $this->postJson(FVR_API.'/claims/preview', ['target_country_code' => 'DE', 'fiscal_year' => 2026, 'period_type' => 'QUARTERLY'])->assertUnprocessable()->assertJsonValidationErrors('quarter');
});

test('a claim below the minimum, without lines or past its deadline cannot be created', function (): void {
    fvSetUp($this);
    fvEntry($this, ['supplier_country_code' => 'AT', 'foreign_vat_amount' => 40, 'net_amount' => 200, 'foreign_vat_rate' => 20]);
    fvEntry($this, ['supplier_country_code' => 'DE', 'document_date' => '2025-03-10']);

    $this->postJson(FVR_API.'/claims', ['target_country_code' => 'AT', 'fiscal_year' => 2026, 'period_type' => 'ANNUAL'])->assertUnprocessable()->assertJsonPath('code', 'below_threshold');
    $this->postJson(FVR_API.'/claims', ['target_country_code' => 'FR', 'fiscal_year' => 2026, 'period_type' => 'ANNUAL'])->assertUnprocessable()->assertJsonPath('code', 'no_items');
    $this->postJson(FVR_API.'/claims', ['target_country_code' => 'IT', 'fiscal_year' => 2026, 'period_type' => 'ANNUAL'])->assertUnprocessable()->assertJsonPath('code', 'domestic_vat');
    // 2025 claims had to be filed by 30 September 2026.
    $this->postJson(FVR_API.'/claims', ['target_country_code' => 'DE', 'fiscal_year' => 2025, 'period_type' => 'ANNUAL'])->assertUnprocessable()->assertJsonPath('code', 'deadline_expired');
});

test('creating a claim takes the eligible lines, numbers the dossier and locks the lines against changes', function (): void {
    fvSetUp($this);
    fvGermanLines($this);

    $claim = fvClaim($this);

    expect($claim->claim_number)->toBe('FVR-2026-DE-0001')
        ->and($claim->status->value)->toBe('DRAFT')
        ->and((string) $claim->total_requested_amount)->toBe('285.00')
        ->and((string) $claim->carried_base_amount)->toBe('285.00')
        ->and($claim->currency_code)->toBe('EUR')
        ->and($claim->statutory_deadline_date->format('Y-m-d'))->toBe('2027-09-30')
        ->and($claim->period_start_date->format('Y-m-d'))->toBe('2026-01-01')
        ->and($claim->period_end_date->format('Y-m-d'))->toBe('2026-12-31');

    $lines = ForeignVatLedgerEntry::query()->where('vat_refund_claim_id', $claim->id)->get();
    expect($lines)->toHaveCount(2)->and($lines->every(fn (ForeignVatLedgerEntry $line): bool => $line->status->value === 'INCLUDED_IN_CLAIM'))->toBeTrue();

    $this->patchJson(FVR_API.'/ledger/'.$lines->first()->id, ['expense_category_code' => 1])->assertUnprocessable()->assertJsonPath('code', 'ledger_entry_locked');
    $this->postJson(FVR_API.'/claims', ['target_country_code' => 'DE', 'fiscal_year' => 2026, 'period_type' => 'ANNUAL'])->assertUnprocessable()->assertJsonPath('code', 'no_items');

    $this->getJson(FVR_API.'/claims/'.$claim->id)->assertOk()
        ->assertJsonPath('data.items_count', 2)->assertJsonCount(2, 'data.items')->assertJsonPath('data.events.0.event', 'created')->assertJsonPath('data.allowed_transitions', ['READY_TO_SUBMIT']);
    $this->getJson(FVR_API.'/claims?status=DRAFT&country=DE&fiscal_year=2026')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson(FVR_API.'/claims?status=SUBMITTED')->assertOk()->assertJsonCount(0, 'data');
});

test('a quarterly claim takes only the lines of its quarter against the quarterly minimum', function (): void {
    fvSetUp($this);
    fvEntry($this, ['document_number' => 'Q1-A', 'supplier_country_code' => 'DE', 'document_date' => '2026-01-20', 'foreign_vat_amount' => 300, 'net_amount' => 1500]);
    fvEntry($this, ['document_number' => 'Q1-B', 'supplier_country_code' => 'DE', 'document_date' => '2026-03-20', 'foreign_vat_amount' => 150, 'net_amount' => 750]);
    fvEntry($this, ['document_number' => 'Q2-A', 'supplier_country_code' => 'DE', 'document_date' => '2026-04-20', 'foreign_vat_amount' => 100, 'net_amount' => 500]);

    $claim = fvClaim($this, ['period_type' => 'QUARTERLY', 'quarter' => 1]);

    expect((string) $claim->total_requested_amount)->toBe('450.00')
        ->and($claim->period_start_date->format('Y-m-d'))->toBe('2026-01-01')
        ->and($claim->period_end_date->format('Y-m-d'))->toBe('2026-03-31')
        ->and(ForeignVatLedgerEntry::query()->where('document_number', 'Q2-A')->value('status')->value)->toBe('ELIGIBLE_ACCUMULATING');
    $this->postJson(FVR_API.'/claims', ['target_country_code' => 'DE', 'fiscal_year' => 2026, 'period_type' => 'QUARTERLY', 'quarter' => 2])->assertUnprocessable()->assertJsonPath('code', 'below_threshold');
});

test('a claim not filed yet can be deleted and its lines are available again, a filed one cannot', function (): void {
    fvSetUp($this);
    fvGermanLines($this);
    $claim = fvClaim($this);

    $this->deleteJson(FVR_API.'/claims/'.$claim->id)->assertOk();
    expect(ForeignVatLedgerEntry::query()->where('status', 'ELIGIBLE_ACCUMULATING')->count())->toBe(2)->and(VatRefundClaim::query()->count())->toBe(0);

    $again = fvClaim($this);
    $this->postJson(FVR_API.'/claims/'.$again->id.'/ready')->assertOk();
    $this->postJson(FVR_API.'/claims/'.$again->id.'/submit', ['protocol_number' => 'P-1'])->assertOk();
    $this->deleteJson(FVR_API.'/claims/'.$again->id)->assertUnprocessable()->assertJsonPath('code', 'claim_locked');
    expect($again->fresh()->claim_number)->toBe('FVR-2026-DE-0002');
});

test('the claim moves through the lifecycle and a partial approval books the refused part and the bank receipt', function (): void {
    fvSetUp($this);
    fvGermanLines($this);
    $claim = fvClaim($this);
    $receivable = app(ForeignVatAccounts::class)->receivable((string) $this->tenant->id)->id;
    $cost = app(ForeignVatAccounts::class)->nonRecoverableExpense((string) $this->tenant->id)->id;

    $this->postJson(FVR_API.'/claims/'.$claim->id.'/submit')->assertUnprocessable()->assertJsonPath('code', 'invalid_transition');
    $this->postJson(FVR_API.'/claims/'.$claim->id.'/decide', ['approved_amount' => 100])->assertUnprocessable()->assertJsonPath('code', 'invalid_transition');

    $this->postJson(FVR_API.'/claims/'.$claim->id.'/ready')->assertOk()->assertJsonPath('data.status', 'READY_TO_SUBMIT');
    $this->postJson(FVR_API.'/claims/'.$claim->id.'/submit', ['protocol_number' => 'PROT-2027-1'])->assertOk()
        ->assertJsonPath('data.status', 'SUBMITTED')->assertJsonPath('data.protocol_number', 'PROT-2027-1')->assertJsonPath('data.locked', true);
    $this->postJson(FVR_API.'/claims/'.$claim->id.'/request-information', ['note' => 'Send the original invoices'])->assertOk()->assertJsonPath('data.status', 'ADDITIONAL_INFO_REQUESTED');
    $this->postJson(FVR_API.'/claims/'.$claim->id.'/submit', [])->assertOk()->assertJsonPath('data.status', 'SUBMITTED');

    $this->postJson(FVR_API.'/claims/'.$claim->id.'/decide', ['approved_amount' => 999])->assertUnprocessable()->assertJsonPath('code', 'approved_amount_invalid');
    $decided = $this->postJson(FVR_API.'/claims/'.$claim->id.'/decide', ['approved_amount' => 200, 'note' => 'Restaurant refused'])->assertOk()
        ->assertJsonPath('data.status', 'PARTIALLY_APPROVED')
        ->assertJsonPath('data.total_approved_amount', '200.00')
        ->assertJsonPath('data.total_rejected_amount', '85.00');

    $claim->refresh();
    $rejection = fvJournal($claim->rejection_journal_entry_id);
    expect($rejection['lines'][$cost])->toBe(['debit' => 85.0, 'credit' => 0.0])
        ->and($rejection['lines'][$receivable])->toBe(['debit' => 0.0, 'credit' => 85.0])
        ->and(ForeignVatLedgerEntry::query()->where('vat_refund_claim_id', $claim->id)->pluck('status')->map->value->unique()->all())->toBe(['PARTIALLY_REJECTED']);

    $this->postJson(FVR_API.'/claims/'.$claim->id.'/reconcile', ['refunded_amount' => 250, 'bank_account_id' => $this->bank->id])->assertUnprocessable()->assertJsonPath('code', 'refund_exceeds_approved');
    $this->postJson(FVR_API.'/claims/'.$claim->id.'/reconcile', ['refunded_amount' => 200, 'bank_account_id' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('bank_account_id');
    $reconciled = $this->postJson(FVR_API.'/claims/'.$claim->id.'/reconcile', ['refunded_amount' => 200, 'bank_account_id' => $this->bank->id, 'received_on' => '2027-07-01', 'note' => 'Transfer of Finanzamt'])->assertOk()
        ->assertJsonPath('data.status', 'REFUNDED')->assertJsonPath('data.total_refunded_amount', '200.00')->assertJsonPath('data.refunded_on', '2027-07-01');

    $claim->refresh();
    $receipt = fvJournal($claim->reconciliation_journal_entry_id);
    expect($receipt['lines'][(string) $this->bankGl->id])->toBe(['debit' => 200.0, 'credit' => 0.0])
        ->and($receipt['lines'][$receivable])->toBe(['debit' => 0.0, 'credit' => 200.0])
        ->and($receipt['debit'])->toBe($receipt['credit'])
        ->and($decided->json('data.events'))->toBeArray()
        ->and($reconciled->json('data.allowed_transitions'))->toBe([])
        ->and(collect($reconciled->json('data.events'))->pluck('event')->all())->toContain('created', 'status_changed', 'refund_reconciled');

    $this->postJson(FVR_API.'/claims/'.$claim->id.'/reconcile', ['refunded_amount' => 10, 'bank_account_id' => $this->bank->id])->assertUnprocessable()->assertJsonPath('code', 'invalid_transition');
});

test('a refund smaller than the request without a prior decision books the refused part in the same entry', function (): void {
    fvSetUp($this);
    fvGermanLines($this);
    $claim = fvClaim($this);
    $this->postJson(FVR_API.'/claims/'.$claim->id.'/ready')->assertOk();
    $this->postJson(FVR_API.'/claims/'.$claim->id.'/submit', ['protocol_number' => 'P-9'])->assertOk();

    $this->postJson(FVR_API.'/claims/'.$claim->id.'/reconcile', ['refunded_amount' => 250, 'bank_account_id' => $this->bank->id])->assertOk()
        ->assertJsonPath('data.status', 'REFUNDED')->assertJsonPath('data.total_rejected_amount', '35.00');

    $claim->refresh();
    $journal = fvJournal($claim->reconciliation_journal_entry_id);
    $cost = app(ForeignVatAccounts::class)->nonRecoverableExpense((string) $this->tenant->id)->id;

    expect($claim->rejection_journal_entry_id)->toBeNull()
        ->and($journal['lines'][$cost])->toBe(['debit' => 35.0, 'credit' => 0.0])
        ->and($journal['lines'][(string) $this->bankGl->id]['debit'])->toBe(250.0)
        ->and($journal['debit'])->toBe($journal['credit']);
});

test('a rejected claim moves the whole VAT to the non-recoverable cost', function (): void {
    fvSetUp($this);
    fvGermanLines($this);
    $claim = fvClaim($this);
    $this->postJson(FVR_API.'/claims/'.$claim->id.'/ready')->assertOk();
    $this->postJson(FVR_API.'/claims/'.$claim->id.'/submit', [])->assertOk();

    $this->postJson(FVR_API.'/claims/'.$claim->id.'/decide', ['approved_amount' => 0])->assertOk()->assertJsonPath('data.status', 'REJECTED')->assertJsonPath('data.total_rejected_amount', '285.00');

    $cost = app(ForeignVatAccounts::class)->nonRecoverableExpense((string) $this->tenant->id)->id;
    expect(fvJournal($claim->fresh()->rejection_journal_entry_id)['lines'][$cost]['debit'])->toBe(285.0)
        ->and(ForeignVatLedgerEntry::query()->where('vat_refund_claim_id', $claim->id)->pluck('status')->map->value->unique()->all())->toBe(['NON_RECOVERABLE_EXPENSED'])
        ->and($claim->fresh()->status->next())->toBe([]);
});

test('the filing after the peremptory deadline is refused unless it is dated before it', function (): void {
    fvSetUp($this);
    fvGermanLines($this);
    $claim = fvClaim($this);
    $this->postJson(FVR_API.'/claims/'.$claim->id.'/ready')->assertOk();

    $this->travelTo('2027-10-02 09:00:00');
    $this->postJson(FVR_API.'/claims/'.$claim->id.'/submit', [])->assertUnprocessable()->assertJsonPath('code', 'deadline_expired');
    $this->postJson(FVR_API.'/claims/'.$claim->id.'/submit', ['submitted_on' => '2027-09-29', 'protocol_number' => 'LATE-RECORD'])->assertOk()->assertJsonPath('data.status', 'SUBMITTED');
});

test('the Swiss claim needs the representative, the certificate and the original invoices before it is ready', function (): void {
    fvSetUp($this);
    $entry = fvEntry($this, ['document_number' => 'ZH-1', 'supplier_country_code' => 'CH', 'currency_code' => 'CHF', 'net_amount' => 20000, 'foreign_vat_rate' => 3.8, 'foreign_vat_amount' => 760, 'exchange_rate_to_base' => 1.05]);
    $claim = fvClaim($this, ['target_country_code' => 'CH']);

    expect($claim->regime_type->value)->toBe('CH_ESTV_RECIPROCITY')->and($claim->currency_code)->toBe('CHF')->and($claim->statutory_deadline_date->format('Y-m-d'))->toBe('2027-06-30');

    // 2027-07-10 is after the 30 June deadline of the 2026 claim: the claim cannot even be created today, so the clock goes back.
    $this->travelTo('2027-05-10 09:00:00');
    $this->postJson(FVR_API.'/claims/'.$claim->id.'/ready')->assertUnprocessable()->assertJsonPath('code', 'fiscal_representative_missing');

    $mandate = $this->postJson(FVR_API.'/mandates', ['country_code' => 'CH', 'representative_name' => 'Treuhand AG', 'mandate_start_date' => '2026-01-01'])->assertCreated();
    $this->postJson(FVR_API.'/claims/'.$claim->id.'/ready')->assertUnprocessable()->assertJsonPath('code', 'certificate_missing');

    $this->post(FVR_API.'/mandates/'.$mandate->json('data.id').'/documents', ['file' => UploadedFile::fake()->create('cert.pdf', 20, 'application/pdf'), 'type' => 'certificate', 'certificate_expiry_date' => '2027-05-01'])->assertOk();
    $this->postJson(FVR_API.'/claims/'.$claim->id.'/ready')->assertUnprocessable()->assertJsonPath('code', 'certificate_expired');

    $this->putJson(FVR_API.'/mandates/'.$mandate->json('data.id'), ['country_code' => 'CH', 'representative_name' => 'Treuhand AG', 'mandate_start_date' => '2026-01-01', 'certificate_expiry_date' => '2028-01-01'])->assertOk();
    $this->postJson(FVR_API.'/claims/'.$claim->id.'/ready')->assertUnprocessable()->assertJsonPath('code', 'attachments_missing');

    $this->post(FVR_API.'/ledger/'.$entry->id.'/attachment', ['file' => UploadedFile::fake()->create('zh-1.pdf', 20, 'application/pdf')])->assertOk();
    $this->postJson(FVR_API.'/claims/'.$claim->id.'/ready')->assertOk()->assertJsonPath('data.status', 'READY_TO_SUBMIT')->assertJsonPath('data.fiscal_representative_mandate_id', $mandate->json('data.id'));
});

test('a refund in a foreign currency is booked at the rate of the receipt with the exchange difference', function (): void {
    fvSetUp($this);
    $this->travelTo('2027-05-10 09:00:00');
    $claim = fvSwissClaim($this);
    $this->postJson(FVR_API.'/claims/'.$claim->id.'/ready')->assertOk();
    $this->postJson(FVR_API.'/claims/'.$claim->id.'/submit', ['protocol_number' => 'ESTV-1'])->assertOk();

    expect((string) $claim->total_requested_amount)->toBe('760.00')->and((string) $claim->carried_base_amount)->toBe('798.00');

    $this->postJson(FVR_API.'/claims/'.$claim->id.'/reconcile', ['refunded_amount' => 760, 'bank_account_id' => $this->bank->id])->assertUnprocessable()->assertJsonPath('code', 'exchange_rate_required');
    $this->postJson(FVR_API.'/claims/'.$claim->id.'/reconcile', ['refunded_amount' => 760, 'bank_account_id' => $this->bank->id, 'exchange_rate' => 1.04])->assertOk()
        ->assertJsonPath('data.status', 'REFUNDED')->assertJsonPath('data.refund_exchange_rate', '1.040000')->assertJsonPath('data.total_rejected_amount', '0.00');

    $claim->refresh();
    $journal = fvJournal($claim->reconciliation_journal_entry_id);
    $receivable = app(ForeignVatAccounts::class)->receivable((string) $this->tenant->id)->id;
    $cost = app(ForeignVatAccounts::class)->nonRecoverableExpense((string) $this->tenant->id)->id;

    // 760 CHF at 1.04 = 790.40 euro on the bank, the receivable carried 798.00: the 7.60 loss goes to the exchange loss (the cost account without FX accounts).
    expect($journal['lines'][(string) $this->bankGl->id]['debit'])->toBe(790.4)
        ->and($journal['lines'][$receivable]['credit'])->toBe(798.0)
        ->and($journal['lines'][$cost]['debit'])->toBe(7.6)
        ->and($journal['debit'])->toBe($journal['credit']);
});

test('the claims of a company are invisible to another company', function (): void {
    fvSetUp($this);
    fvGermanLines($this);
    $claim = fvClaim($this);

    treasuryActor($this, 'accountant', $this->otherTenant);

    $this->getJson(FVR_API.'/claims')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson(FVR_API.'/claims/'.$claim->id)->assertNotFound();
    $this->postJson(FVR_API.'/claims/'.$claim->id.'/ready')->assertNotFound();
    $this->deleteJson(FVR_API.'/claims/'.$claim->id)->assertNotFound();
});

test('a claim cannot be created from the lines of another claim period or with a foreign id', function (): void {
    fvSetUp($this);
    fvGermanLines($this);
    $other = fvEntry($this, ['supplier_country_code' => 'DE', 'document_date' => '2025-08-10']);

    $this->postJson(FVR_API.'/claims/preview', ['target_country_code' => 'DE', 'fiscal_year' => 2026, 'period_type' => 'ANNUAL', 'ledger_ids' => [$other->id]])->assertOk()
        ->assertJsonPath('data.items_count', 0)->assertJsonPath('data.blockers', ['no_items']);

    $mandate = FiscalRepresentativeMandate::query()->count();
    expect($mandate)->toBe(0);
});
