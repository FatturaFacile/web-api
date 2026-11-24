<?php

declare(strict_types=1);

use App\Models\SupplierPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\AccountsPayable\Models\ApOpenItem;
use Modules\AccountsPayable\Models\ApPaymentRun;
use Modules\Treasury\Models\PaymentProposal;
use Modules\Treasury\Models\PaymentProposalLine;

require_once __DIR__.'/AccountsPayableSupport.php';

uses(RefreshDatabase::class);

/**
 * Alfa (IBAN X): A1 1000 due in 5 days, A2 500 due in 3 days, A3 300 due in 60 days, a credit note of 200.
 * Beta (IBAN Y, 2% cash discount within 10 days): B1 500 of 3 days ago, due in 57 days: its discount expires in 7 days.
 */
beforeEach(function (): void {
    apSetUp($this);
    $this->beta = apSupplier($this->tenant, 'Fornitore Beta SPA', 'IT84S0542811101000000654321');
    $this->postJson('/api/v1/accounts-payable/supplier-terms', ['supplier_id' => $this->beta->id, 'skonto_percentage' => 2, 'skonto_days' => 10, 'net_days' => 60])->assertCreated();

    $this->a1 = apInvoice($this, $this->supplier, 'A-1', '1000.00', 5);
    $this->a2 = apInvoice($this, $this->supplier, 'A-2', '500.00', 3);
    $this->a3 = apInvoice($this, $this->supplier, 'A-3', '300.00', 60);
    $this->credit = apCreditNote($this, $this->supplier, 'A-NC', '200.00', 0);
    $this->b1 = apInvoice($this, $this->beta, 'B-1', '500.00', 57, ['date' => now()->subDays(3)->toDateString()]);
    $this->admin = treasuryActor($this, 'admin');
    treasuryActAs($this, $this->accountant);
});

function apDraftRun(object $test, array $overrides = []): array
{
    treasuryActAs($test, $test->accountant);

    return $test->postJson('/api/v1/accounts-payable/payment-runs', apRunPayload($overrides))->assertCreated()->json('data');
}

function apAuthorizedRun(object $test): array
{
    $run = apDraftRun($test);
    $test->postJson("/api/v1/accounts-payable/payment-runs/{$run['id']}/submit")->assertOk();
    treasuryActAs($test, $test->admin);

    return $test->postJson("/api/v1/accounts-payable/payment-runs/{$run['id']}/approve")->assertOk()->json('data');
}

function apStatuses(object $test): array
{
    return ApOpenItem::withoutGlobalScopes()->where('tenant_id', $test->tenant->id)->pluck('status', 'document_number')->all();
}

it('previews the run: due invoices, the discount, the credit note offset and the transfers by creditor IBAN', function (): void {
    $response = $this->postJson('/api/v1/accounts-payable/payment-runs/preview', apRunPayload())->assertOk();
    $plan = $response->json('data');
    $items = collect($plan['items'])->keyBy('document_number');
    $transfers = collect($plan['transfers'])->keyBy('creditor_iban');

    // A-3 is not due by the cutoff; B-1 is selected because its discount expires within the cutoff.
    expect($items->keys()->sort()->values()->all())->toBe(['A-1', 'A-2', 'A-NC', 'B-1'])
        ->and($plan['totals'])->toBe(['debit_total' => '2000.00', 'credit_netted_total' => '200.00', 'skonto_total' => '10.00', 'net_total' => '1790.00', 'items_count' => 4, 'transfers_count' => 2])
        ->and($items['B-1'])->toMatchArray(['selection_reason' => 'skonto', 'skonto_percentage' => '2.00', 'skonto_deadline' => now()->addDays(7)->toDateString(), 'skonto_amount' => '10.00', 'cash_amount' => '490.00'])
        ->and($items['A-2'])->toMatchArray(['selection_reason' => 'due', 'credit_applied_amount' => '200.00', 'cash_amount' => '300.00'])
        ->and($items['A-1'])->toMatchArray(['credit_applied_amount' => '0.00', 'cash_amount' => '1000.00'])
        ->and($items['A-NC'])->toMatchArray(['kind' => 'credit_note', 'selection_reason' => 'credit_note', 'credit_used_amount' => '200.00'])
        ->and($transfers['IT60X0542811101000000123456'])->toMatchArray(['gross_amount' => '1500.00', 'credit_netted_amount' => '200.00', 'skonto_amount' => '0.00', 'amount' => '1300.00', 'items_count' => 2, 'currency' => 'EUR'])
        ->and($transfers['IT84S0542811101000000654321'])->toMatchArray(['gross_amount' => '500.00', 'skonto_amount' => '10.00', 'amount' => '490.00'])
        ->and($plan['offsets'])->toBe([['debit_item_id' => apItem($this->a2)->id, 'credit_item_id' => apItem($this->credit)->id, 'amount' => '200.00']])
        ->and($plan['excluded'])->toBe([]);

    // A preview saves nothing and reserves nothing.
    expect(ApPaymentRun::withoutGlobalScopes()->count())->toBe(0)
        ->and(array_unique(array_values(apStatuses($this))))->toBe(['open']);
});

it('previews a run without discounts or without netting', function (): void {
    $plain = $this->postJson('/api/v1/accounts-payable/payment-runs/preview', apRunPayload(['include_skonto' => false, 'net_credit_notes' => false]))->assertOk()->json('data');

    // Without the discount B-1 is not due by the cutoff either.
    expect(collect($plain['items'])->pluck('document_number')->sort()->values()->all())->toBe(['A-1', 'A-2'])
        ->and($plain['totals'])->toMatchArray(['debit_total' => '1500.00', 'credit_netted_total' => '0.00', 'skonto_total' => '0.00', 'net_total' => '1500.00']);

    $onlyBeta = $this->postJson('/api/v1/accounts-payable/payment-runs/preview', apRunPayload(['supplier_ids' => [$this->beta->id]]))->assertOk()->json('data');
    expect(collect($onlyBeta['items'])->pluck('document_number')->all())->toBe(['B-1']);
});

it('leaves disputed, blocked and unapproved invoices and invoices without IBAN out of the proposal, and says why', function (): void {
    $disputed = apInvoice($this, $this->supplier, 'X-DISP', '110.00', 1);
    $blocked = apInvoice($this, $this->supplier, 'X-BLOCK', '120.00', 1);
    $unapproved = apInvoice($this, $this->supplier, 'X-UNAPP', '130.00', 1);
    $unapproved->forceFill(['approval_status' => 'pending'])->saveQuietly();
    $noIban = apInvoice($this, apSupplier($this->tenant, 'Senza IBAN SRL', null), 'X-NOIBAN', '140.00', 1);
    apSync($this);
    $this->postJson('/api/v1/accounts-payable/open-items/'.apItem($disputed)->id.'/dispute', ['reason' => 'Wrong price'])->assertOk();
    $this->postJson('/api/v1/accounts-payable/open-items/'.apItem($blocked)->id.'/block', ['reason' => 'Verification'])->assertOk();

    $plan = $this->postJson('/api/v1/accounts-payable/payment-runs/preview', apRunPayload())->assertOk()->json('data');
    $reasons = collect($plan['excluded'])->pluck('reason', 'document_number')->all();
    $numbers = collect($plan['items'])->pluck('document_number')->all();

    expect($reasons)->toEqual(['X-DISP' => 'disputed', 'X-BLOCK' => 'administrative_block', 'X-UNAPP' => 'invoice_not_approved', 'X-NOIBAN' => 'missing_iban'])
        ->and($numbers)->not->toContain('X-DISP', 'X-BLOCK', 'X-UNAPP', 'X-NOIBAN');
});

it('prepares a draft run that reserves its items, and refuses to propose them twice', function (): void {
    $run = apDraftRun($this);

    expect($run)->toMatchArray(['status' => 'draft', 'currency' => 'EUR', 'include_skonto' => true, 'net_credit_notes' => true])
        ->and($run['run_number'])->toStartWith('DP-'.now()->format('Ymd'))
        ->and($run['totals'])->toBe(['debit_total' => '2000.00', 'credit_netted_total' => '200.00', 'skonto_total' => '10.00', 'net_total' => '1790.00', 'items_count' => 4, 'transfers_count' => 2])
        ->and($run['items'])->toHaveCount(4)
        ->and($run['transfers'])->toHaveCount(2)
        ->and($run['offsets'])->toHaveCount(1)
        ->and($run['workflow'])->toMatchArray(['created_by' => $this->accountant->id, 'submitted_by' => null, 'approved_by' => null, 'executed_by' => null])
        ->and(apStatuses($this))->toEqual(['A-1' => 'in_proposal', 'A-2' => 'in_proposal', 'A-3' => 'open', 'A-NC' => 'in_proposal', 'B-1' => 'in_proposal']);

    // Everything due is already in the draft: a second run has nothing to propose.
    $second = $this->postJson('/api/v1/accounts-payable/payment-runs', apRunPayload())->assertUnprocessable();
    expect(apErrorCode($second))->toBe('no_items_match');

    // The reserved items are not payable from the ledger, and the run can be read back.
    $this->getJson('/api/v1/accounts-payable/open-items?only_payable=1')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/accounts-payable/payment-runs/{$run['id']}")->assertOk()->assertJsonPath('data.run_number', $run['run_number'])->assertJsonCount(4, 'data.items');
    $this->getJson('/api/v1/accounts-payable/payment-runs?status=draft')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/accounts-payable/payment-runs?status=executed')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/accounts-payable/payment-runs?supplier_id='.$this->beta->id)->assertOk()->assertJsonCount(1, 'data');
});

it('validates the criteria of the run', function (): void {
    $this->postJson('/api/v1/accounts-payable/payment-runs', [])->assertUnprocessable()->assertJsonValidationErrors(['currency', 'cutoff_date', 'execution_date']);
    $this->postJson('/api/v1/accounts-payable/payment-runs', apRunPayload(['execution_date' => now()->subDay()->toDateString()]))->assertUnprocessable()->assertJsonValidationErrors(['execution_date']);
    $this->postJson('/api/v1/accounts-payable/payment-runs', apRunPayload(['bank_account_id' => (string) Str::uuid()]))->assertUnprocessable()->assertJsonValidationErrors(['bank_account_id']);
    $this->postJson('/api/v1/accounts-payable/payment-runs', apRunPayload(['supplier_ids' => [(string) Str::uuid()]]))->assertUnprocessable()->assertJsonValidationErrors(['supplier_ids.0']);

    // Nothing is due in another currency.
    $empty = $this->postJson('/api/v1/accounts-payable/payment-runs', apRunPayload(['currency' => 'CHF']))->assertUnprocessable();
    expect(apErrorCode($empty))->toBe('no_items_match');
});

it('goes from draft to the CFO and back, and only the CFO decides', function (): void {
    $run = apDraftRun($this);
    $base = "/api/v1/accounts-payable/payment-runs/{$run['id']}";

    $this->postJson("{$base}/approve")->assertForbidden();
    $this->postJson("{$base}/execute")->assertStatus(409);

    $submitted = $this->postJson("{$base}/submit")->assertOk();
    expect($submitted->json('data'))->toMatchArray(['status' => 'pending_cfo_approval'])
        ->and($submitted->json('data.workflow.submitted_by'))->toBe($this->accountant->id);

    // The accountant prepares and submits, but does not approve: the approval needs the CFO permission.
    $this->postJson("{$base}/approve")->assertForbidden();
    $this->postJson("{$base}/reject", ['reason' => 'No'])->assertForbidden();
    $this->postJson("{$base}/submit")->assertStatus(409);

    // Taken back from the CFO it is a draft again.
    $this->postJson("{$base}/recall")->assertOk()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.workflow.submitted_by', null);
    $this->postJson("{$base}/recall")->assertStatus(409);
    $this->postJson("{$base}/submit")->assertOk();

    treasuryActAs($this, $this->admin);
    $approved = $this->postJson("{$base}/approve")->assertOk();

    expect($approved->json('data'))->toMatchArray(['status' => 'authorized'])
        ->and($approved->json('data.workflow.approved_by'))->toBe($this->admin->id)
        ->and($approved->json('data.workflow.approved_at'))->not->toBeNull()
        ->and(apStatuses($this))->toEqual(['A-1' => 'authorized', 'A-2' => 'authorized', 'A-3' => 'open', 'A-NC' => 'authorized', 'B-1' => 'authorized']);

    // A decided run cannot be decided again, nor cancelled into a different state by a second approval.
    $this->postJson("{$base}/approve")->assertStatus(409);
    $this->postJson("{$base}/reject", ['reason' => 'Late'])->assertStatus(409);
});

it('refuses the approval of the person who prepared the run, and of the one who submitted it', function (): void {
    treasuryActAs($this, $this->admin);
    $run = $this->postJson('/api/v1/accounts-payable/payment-runs', apRunPayload())->assertCreated()->json('data');
    $base = "/api/v1/accounts-payable/payment-runs/{$run['id']}";
    $this->postJson("{$base}/submit")->assertOk();

    // The administrator prepared and submitted the run: another person has to approve it.
    $response = $this->postJson("{$base}/approve")->assertForbidden();
    expect(apErrorCode($response))->toBe('segregation_of_duties');

    // The accountant takes it over and submits it again: the administrator who prepared it still cannot approve.
    treasuryActAs($this, $this->accountant);
    $this->postJson("{$base}/recall")->assertOk();
    $this->postJson("{$base}/submit")->assertOk();
    treasuryActAs($this, $this->admin);
    expect(apErrorCode($this->postJson("{$base}/approve")->assertForbidden()))->toBe('segregation_of_duties');

    // A second administrator approves.
    $owner = treasuryActor($this, 'owner');
    $this->postJson("{$base}/approve")->assertOk()->assertJsonPath('data.workflow.approved_by', $owner->id);
});

it('lets the CFO reject a run with a reason and frees its items', function (): void {
    $run = apDraftRun($this);
    $base = "/api/v1/accounts-payable/payment-runs/{$run['id']}";
    $this->postJson("{$base}/submit")->assertOk();

    treasuryActAs($this, $this->admin);
    $this->postJson("{$base}/reject", [])->assertUnprocessable()->assertJsonValidationErrors(['reason']);
    $rejected = $this->postJson("{$base}/reject", ['reason' => 'Cash flow does not allow it'])->assertOk();

    expect($rejected->json('data'))->toMatchArray(['status' => 'rejected'])
        ->and($rejected->json('data.workflow'))->toMatchArray(['rejected_by' => $this->admin->id, 'rejection_reason' => 'Cash flow does not allow it'])
        ->and(array_unique(array_values(apStatuses($this))))->toBe(['open']);

    // The items can go in a new run.
    treasuryActAs($this, $this->accountant);
    expect($this->postJson('/api/v1/accounts-payable/payment-runs', apRunPayload())->assertCreated()->json('data.totals.items_count'))->toBe(4);
});

it('cancels a run that was not executed and frees its items', function (): void {
    $run = apAuthorizedRun($this);
    $base = "/api/v1/accounts-payable/payment-runs/{$run['id']}";

    $this->postJson("{$base}/cancel", ['reason' => 'Bank holiday'])->assertOk()->assertJsonPath('data.status', 'cancelled');
    expect(array_unique(array_values(apStatuses($this))))->toBe(['open']);

    $this->postJson("{$base}/cancel")->assertStatus(409);
    $this->postJson("{$base}/execute")->assertStatus(409);
});

it('executes an authorized run: payments, the discount and the credit note offset are recorded and the items are closed', function (): void {
    $run = apAuthorizedRun($this);
    $base = "/api/v1/accounts-payable/payment-runs/{$run['id']}";

    $executed = $this->postJson("{$base}/execute")->assertOk();
    $payments = fn (string $invoiceId): array => SupplierPayment::withoutGlobalScopes()->where('passive_invoice_id', $invoiceId)->orderBy('type')->get()
        ->mapWithKeys(fn (SupplierPayment $payment): array => [$payment->type => bcadd((string) $payment->amount, '0', 2)])->all();

    expect($executed->json('data'))->toMatchArray(['status' => 'executed'])
        ->and($executed->json('data.workflow.executed_by'))->toBe($this->admin->id)
        ->and(collect($executed->json('data.transfers'))->pluck('status')->unique()->all())->toBe(['paid'])
        ->and($payments($this->a1->id))->toEqual(['payment' => '1000.00'])
        ->and($payments($this->a2->id))->toEqual(['credit_offset' => '200.00', 'payment' => '300.00'])
        ->and($payments($this->credit->id))->toEqual(['credit_used' => '200.00'])
        ->and($payments($this->b1->id))->toEqual(['cash_discount' => '10.00', 'payment' => '490.00'])
        ->and($payments($this->a3->id))->toEqual([])
        ->and(SupplierPayment::withoutGlobalScopes()->where('passive_invoice_id', $this->a2->id)->where('type', 'credit_offset')->value('offset_passive_invoice_id'))->toBe($this->credit->id)
        ->and(apStatuses($this))->toEqual(['A-1' => 'paid', 'A-2' => 'paid', 'A-3' => 'open', 'A-NC' => 'paid', 'B-1' => 'paid'])
        ->and(array_map(fn ($invoice): string => $invoice->refresh()->payment_status, [$this->a1, $this->a2, $this->credit, $this->b1, $this->a3]))->toBe(['paid', 'paid', 'paid', 'paid', 'unpaid']);

    $b1 = ApOpenItem::withoutGlobalScopes()->where('document_number', 'B-1')->firstOrFail();
    expect([(string) $b1->paid_amount, (string) $b1->discount_taken_amount, (string) $b1->open_amount])->toBe(['500.00', '10.00', '0.00']);

    // An executed run cannot be executed again.
    $this->postJson("{$base}/execute")->assertStatus(409);

    // The history of an item tells the whole story.
    $events = collect($this->getJson('/api/v1/accounts-payable/open-items/'.apItem($this->b1)->id.'/history')->assertOk()->json('data'))->pluck('event')->all();
    expect($events)->toBe(['proposed', 'authorized', 'paid']);
});

it('uses the part of a credit note that the run needs and leaves the rest open', function (): void {
    $big = apCreditNote($this, $this->supplier, 'A-NC2', '1500.00', 0);
    $run = apAuthorizedRun($this);

    $executed = $this->postJson("/api/v1/accounts-payable/payment-runs/{$run['id']}/execute")->assertOk();

    // 1500 owed to Alfa, 1700 of credit: A-NC (200) is used up and 1300 of A-NC2 offset (1500 netted), so nothing is transferred to Alfa.
    $alfa = collect($executed->json('data.transfers'))->firstWhere('creditor_iban', 'IT60X0542811101000000123456');
    $statuses = apStatuses($this);

    expect($alfa)->toMatchArray(['amount' => '0.00', 'credit_netted_amount' => '1500.00', 'status' => 'netted'])
        ->and($statuses['A-NC'])->toBe('paid')
        ->and($statuses['A-NC2'])->toBe('open')
        ->and($statuses['A-1'])->toBe('paid')
        ->and((string) ApOpenItem::withoutGlobalScopes()->where('document_number', 'A-NC2')->value('open_amount'))->toBe('200.00')
        ->and(SupplierPayment::withoutGlobalScopes()->where('passive_invoice_id', $big->id)->where('type', 'credit_used')->sum('amount'))->toEqual(1300)
        ->and(SupplierPayment::withoutGlobalScopes()->where('passive_invoice_id', $this->a1->id)->where('type', 'payment')->count())->toBe(0);
});

it('removes an invoice from a draft and works the run out again', function (): void {
    $run = apDraftRun($this);
    $base = "/api/v1/accounts-payable/payment-runs/{$run['id']}";

    $recalculated = $this->deleteJson("{$base}/items/".apItem($this->a2)->id)->assertOk();

    // Without A-2 the credit note is offset against A-1 and B-1 keeps its discount.
    expect(collect($recalculated->json('data.items'))->pluck('document_number')->sort()->values()->all())->toBe(['A-1', 'A-NC', 'B-1'])
        ->and($recalculated->json('data.totals'))->toBe(['debit_total' => '1500.00', 'credit_netted_total' => '200.00', 'skonto_total' => '10.00', 'net_total' => '1290.00', 'items_count' => 3, 'transfers_count' => 2])
        ->and(apStatuses($this))->toEqual(['A-1' => 'in_proposal', 'A-2' => 'open', 'A-3' => 'open', 'A-NC' => 'in_proposal', 'B-1' => 'in_proposal']);

    // A credit note offset cannot be removed on its own.
    $credit = $this->deleteJson("{$base}/items/".apItem($this->credit)->id)->assertUnprocessable();
    expect(apErrorCode($credit))->toBe('credit_note_row_not_removable');
    $this->deleteJson("{$base}/items/".apItem($this->a3)->id)->assertNotFound();

    // Removing the last invoices cancels the run.
    $this->deleteJson("{$base}/items/".apItem($this->a1)->id)->assertOk();
    $this->deleteJson("{$base}/items/".apItem($this->b1)->id)->assertOk()->assertJsonPath('data.status', 'cancelled');
    expect(array_unique(array_values(apStatuses($this))))->toBe(['open']);
});

it('cannot remove an invoice from a run that went to the CFO', function (): void {
    $run = apDraftRun($this);
    $this->postJson("/api/v1/accounts-payable/payment-runs/{$run['id']}/submit")->assertOk();

    $this->deleteJson("/api/v1/accounts-payable/payment-runs/{$run['id']}/items/".apItem($this->a1)->id)->assertStatus(409);
});

it('stops a run that holds a disputed item until the item is removed or released', function (): void {
    $run = apDraftRun($this);
    $base = "/api/v1/accounts-payable/payment-runs/{$run['id']}";
    $itemId = apItem($this->a1)->id;

    $this->postJson("/api/v1/accounts-payable/open-items/{$itemId}/dispute", ['reason' => 'Quantities do not match'])->assertOk();

    $refused = $this->postJson("{$base}/submit")->assertStatus(409);
    expect(apErrorCode($refused))->toBe('run_contains_held_items')
        ->and($refused->json('invalid_params.0.params.documents'))->toBe('A-1');

    // Released while the run is still a draft: the item goes back in.
    $this->postJson("/api/v1/accounts-payable/open-items/{$itemId}/release", ['reason' => 'Supplier issued a credit note'])->assertOk()->assertJsonPath('data.status', 'in_proposal');
    $this->postJson("{$base}/submit")->assertOk();

    // Or removed from the run: the dispute stays.
    $this->postJson("{$base}/recall")->assertOk();
    $this->postJson("/api/v1/accounts-payable/open-items/{$itemId}/block", ['reason' => 'Supplier verification'])->assertOk();
    $this->deleteJson("{$base}/items/{$itemId}")->assertOk();
    expect(apStatuses($this)['A-1'])->toBe('blocked');
    $this->postJson("{$base}/submit")->assertOk();
});

it('stops the approval and the execution when an item is held meanwhile', function (): void {
    $run = apDraftRun($this);
    $base = "/api/v1/accounts-payable/payment-runs/{$run['id']}";
    $this->postJson("{$base}/submit")->assertOk();

    // Disputed while the CFO looks at the run.
    $this->postJson('/api/v1/accounts-payable/open-items/'.apItem($this->b1)->id.'/dispute', ['reason' => 'Invoice already paid'])->assertOk();
    treasuryActAs($this, $this->admin);
    $approval = $this->postJson("{$base}/approve")->assertStatus(409);
    expect(apErrorCode($approval))->toBe('run_contains_held_items');

    // Released, approved, then blocked before the payment: the execution is refused and nothing is paid.
    treasuryActAs($this, $this->accountant);
    $this->postJson('/api/v1/accounts-payable/open-items/'.apItem($this->b1)->id.'/release', ['reason' => 'Checked'])->assertOk();
    treasuryActAs($this, $this->admin);
    $this->postJson("{$base}/approve")->assertOk();
    $this->postJson('/api/v1/accounts-payable/open-items/'.apItem($this->a2)->id.'/block', ['reason' => 'Bank details changed'])->assertOk();

    $execution = $this->postJson("{$base}/execute")->assertStatus(409);

    expect(apErrorCode($execution))->toBe('run_contains_held_items')
        ->and(SupplierPayment::withoutGlobalScopes()->count())->toBe(0)
        ->and($this->getJson("/api/v1/accounts-payable/payment-runs/{$run['id']}")->json('data.status'))->toBe('authorized');
});

it('asks for a new run when an invoice was paid elsewhere after the proposal', function (): void {
    $run = apDraftRun($this);
    $this->a1->forceFill(['total_paid' => '400.00', 'payment_status' => 'partially_paid'])->save();

    $response = $this->postJson("/api/v1/accounts-payable/payment-runs/{$run['id']}/submit")->assertStatus(409);

    expect(apErrorCode($response))->toBe('run_item_changed')
        ->and($response->json('invalid_params.0.params.document'))->toBe('A-1');
});

it('does not propose the invoices a treasury proposal already holds', function (): void {
    $bank = treasuryBank($this->tenant, 'IT60X0542811101000000123456', $this->accounts['1001']);
    $proposal = PaymentProposal::query()->create([
        'tenant_id' => $this->tenant->id, 'proposal_number' => 'PP-TEST', 'bank_account_id' => $bank->id, 'status' => 'draft', 'format' => 'sepa_pain_001_it',
        'execution_date' => now()->toDateString(), 'due_until' => now()->toDateString(), 'currency' => 'EUR', 'created_by' => $this->accountant->id,
    ]);
    PaymentProposalLine::query()->create([
        'tenant_id' => $this->tenant->id, 'payment_proposal_id' => $proposal->id, 'passive_invoice_id' => $this->a1->id, 'supplier_id' => $this->supplier->id,
        'selection_reason' => 'due', 'due_date' => now()->addDays(5)->toDateString(), 'open_amount' => '1000.00', 'discount_amount' => '0.00', 'amount' => '1000.00',
    ]);

    $plan = $this->postJson('/api/v1/accounts-payable/payment-runs/preview', apRunPayload(['supplier_ids' => [$this->supplier->id]]))->assertOk()->json('data');

    expect(collect($plan['items'])->pluck('document_number')->all())->not->toContain('A-1')
        ->and(collect($plan['excluded'])->pluck('reason', 'document_number')->all())->toEqual(['A-1' => 'in_treasury_proposal']);
});
