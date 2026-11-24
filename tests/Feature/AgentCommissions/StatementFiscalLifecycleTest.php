<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Modules\AgentCommissions\Mail\CommissionStatementMail;
use Modules\AgentCommissions\Models\AgentYearBalance;
use Modules\AgentCommissions\Models\CommissionEntry;

require_once __DIR__.'/AgentCommissionsSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    agcSetUp($this);
    $this->agent = agcAgent($this);
    agcRule($this, 'General', ['rate' => '10']);
});

/**
 * @return array<string, mixed>
 */
function agcStatementPayload(object $test, string $from, string $to, string $type = 'quarterly'): array
{
    return ['agent_profile_id' => $test->agent->id, 'period_start' => $from, 'period_end' => $to, 'period_type' => $type];
}

it('computes Enasarco, FIRR, withholding and VAT per statement and stops the contribution exactly at the yearly ceiling', function (): void {
    agcOverride($this, 'enasarco_massimale', '1000', 'single');
    agcInvoice($this, [['net' => '8000.00']], '2027-03-10'); // commission 800
    agcInvoice($this, [['net' => '4000.00']], '2027-04-10'); // commission 400
    agcInvoice($this, [['net' => '2000.00']], '2027-08-10'); // commission 200

    $this->postJson('/api/v1/agent-commissions/statements', agcStatementPayload($this, '2027-01-01', '2027-03-31'))
        ->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.gross_commission', '800.00')
        ->assertJsonPath('data.enasarco_base', '800.00')
        ->assertJsonPath('data.enasarco_agent', '70.00')
        ->assertJsonPath('data.enasarco_company', '70.00')
        ->assertJsonPath('data.firr_accrual', '32.00')
        ->assertJsonPath('data.withholding_base', '365.00')
        ->assertJsonPath('data.withholding_amount', '83.95')
        ->assertJsonPath('data.vat_amount', '176.00')
        ->assertJsonPath('data.net_payable', '822.05')
        ->assertJsonPath('data.massimale_reached', false)
        ->assertJsonCount(1, 'data.lines');

    // The invoice that crosses the ceiling is split exactly: only 200 of its 400 is still below it.
    $this->postJson('/api/v1/agent-commissions/statements', agcStatementPayload($this, '2027-04-01', '2027-06-30'))
        ->assertCreated()
        ->assertJsonPath('data.gross_commission', '400.00')
        ->assertJsonPath('data.enasarco_base', '200.00')
        ->assertJsonPath('data.enasarco_agent', '17.50')
        ->assertJsonPath('data.enasarco_company', '17.50')
        ->assertJsonPath('data.firr_accrual', '16.00')
        ->assertJsonPath('data.withholding_base', '191.25')
        ->assertJsonPath('data.withholding_amount', '43.99')
        ->assertJsonPath('data.vat_amount', '88.00')
        ->assertJsonPath('data.net_payable', '426.51')
        ->assertJsonPath('data.massimale_reached', true);

    // Once the ceiling is reached the social security withholding is zero.
    $this->postJson('/api/v1/agent-commissions/statements', agcStatementPayload($this, '2027-07-01', '2027-09-30'))
        ->assertCreated()
        ->assertJsonPath('data.gross_commission', '200.00')
        ->assertJsonPath('data.enasarco_base', '0.00')
        ->assertJsonPath('data.enasarco_agent', '0.00')
        ->assertJsonPath('data.enasarco_company', '0.00')
        ->assertJsonPath('data.firr_accrual', '8.00')
        ->assertJsonPath('data.net_payable', '221.00');

    $balance = AgentYearBalance::query()->where('agent_profile_id', $this->agent->id)->where('year', 2027)->firstOrFail();
    expect((string) $balance->enasarco_base_ytd)->toBe('1000.00')
        ->and((string) $balance->enasarco_agent_ytd)->toBe('87.50')
        ->and((string) $balance->firr_ytd)->toBe('56.00');

    $this->getJson('/api/v1/agent-commissions/enasarco/overview?year=2027')
        ->assertOk()
        ->assertJsonPath('data.0.massimale', '1000.00')
        ->assertJsonPath('data.0.massimale_remaining', '0.00')
        ->assertJsonPath('data.0.massimale_reached', true)
        ->assertJsonPath('data.0.firr_ytd', '56.00');
});

it('tracks the minimale and keeps no social security for a Swiss direct-commission agent', function (): void {
    $this->getJson('/api/v1/agent-commissions/enasarco/overview?year=2027')
        ->assertOk()
        ->assertJsonPath('data.0.minimale', '878.00')
        ->assertJsonPath('data.0.minimale_reached', false)
        ->assertJsonPath('data.0.minimale_shortfall', '878.00');

    $swissRep = agcRepresentative($this, 'Stefan Meier', 'stefan@example.com');
    $swiss = agcAgent($this, $swissRep, ['agent_type' => 'direct', 'fiscal_regime' => 'swiss_direct', 'settlement_channel' => 'salary']);
    agcInvoice($this, [['net' => '1000.00']], '2027-03-10', ['sales_rep_id' => $swissRep->id]);

    $statement = $this->postJson('/api/v1/agent-commissions/statements', ['agent_profile_id' => $swiss->id, 'period_start' => '2027-01-01', 'period_end' => '2027-03-31'])
        ->assertCreated()
        ->assertJsonPath('data.enasarco_agent', '0.00')
        ->assertJsonPath('data.vat_amount', '0.00')
        ->assertJsonPath('data.withholding_amount', '0.00')
        ->assertJsonPath('data.net_payable', '100.00')
        ->json('data.id');

    $this->postJson("/api/v1/agent-commissions/statements/{$statement}/approve")->assertOk();
    $this->postJson("/api/v1/agent-commissions/statements/{$statement}/self-billing", [])->assertStatus(422)->assertJsonPath('code', 'settled_on_salary');
    $this->postJson("/api/v1/agent-commissions/statements/{$statement}/pay", ['reference' => 'PAYROLL-03'])->assertOk()->assertJsonPath('data.status', 'paid');
});

it('moves a statement through approval, the commission invoice data and the payment, and refuses to void it once invoiced', function (): void {
    agcInvoice($this, [['net' => '1000.00']], '2027-03-10');
    $id = $this->postJson('/api/v1/agent-commissions/statements', agcStatementPayload($this, '2027-01-01', '2027-03-31'))->assertCreated()->json('data.id');

    $this->postJson("/api/v1/agent-commissions/statements/{$id}/pay", [])->assertStatus(409)->assertJsonPath('code', 'only_approved_can_be_paid');
    $this->postJson("/api/v1/agent-commissions/statements/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
    $this->postJson("/api/v1/agent-commissions/statements/{$id}/approve")->assertStatus(409)->assertJsonPath('code', 'only_draft_can_be_approved');

    $invoiced = $this->postJson("/api/v1/agent-commissions/statements/{$id}/self-billing", ['date' => '2027-04-02'])->assertOk();
    $invoiced->assertJsonPath('data.status', 'invoiced')->assertJsonPath('data.self_billing_number', 'AF-2027-00001')
        ->assertJsonPath('data.self_billing_payload.taxable_amount', '100.00')
        ->assertJsonPath('data.self_billing_payload.document_total', '122.00')
        ->assertJsonPath('data.self_billing_payload.vat_rate', '22.0000');

    $this->postJson("/api/v1/agent-commissions/statements/{$id}/void")->assertStatus(409)->assertJsonPath('code', 'cannot_void');
    $this->postJson("/api/v1/agent-commissions/statements/{$id}/pay", ['reference' => 'SEPA-1', 'paid_on' => '2027-04-15'])->assertOk()->assertJsonPath('data.status', 'paid')->assertJsonPath('data.payment_reference', 'SEPA-1');
    expect((string) CommissionEntry::query()->first()->status)->toBe('settled');
});

it('voids a statement, gives its lines back to the ledger and restores the yearly balance', function (): void {
    agcInvoice($this, [['net' => '1000.00']], '2027-03-10');
    $id = $this->postJson('/api/v1/agent-commissions/statements', agcStatementPayload($this, '2027-01-01', '2027-03-31'))->assertCreated()->json('data.id');
    expect((string) AgentYearBalance::query()->first()->commission_ytd)->toBe('100.00');

    $this->postJson("/api/v1/agent-commissions/statements/{$id}/void")->assertOk()->assertJsonPath('data.status', 'void');

    $entry = CommissionEntry::query()->first();
    expect((string) $entry->settled_amount)->toBe('0.00')->and($entry->status)->toBe('eligible')
        ->and((string) AgentYearBalance::query()->first()->commission_ytd)->toBe('0.00');

    $this->postJson('/api/v1/agent-commissions/statements', agcStatementPayload($this, '2027-01-01', '2027-03-31'))->assertCreated();
});

it('refuses a period that spans two years, overlaps a statement or has nothing to settle', function (): void {
    agcInvoice($this, [['net' => '1000.00']], '2027-03-10');

    $this->postJson('/api/v1/agent-commissions/statements', agcStatementPayload($this, '2027-06-01', '2028-01-31'))->assertStatus(422)->assertJsonPath('code', 'period_spans_years');
    $this->postJson('/api/v1/agent-commissions/statements', agcStatementPayload($this, '2027-01-01', '2027-02-28'))->assertStatus(422)->assertJsonPath('code', 'nothing_to_settle');
    $this->postJson('/api/v1/agent-commissions/statements', agcStatementPayload($this, '2027-01-01', '2027-03-31'))->assertCreated();
    $this->postJson('/api/v1/agent-commissions/statements', agcStatementPayload($this, '2027-03-01', '2027-04-30'))->assertStatus(422)->assertJsonPath('code', 'period_overlap');
});

it('previews a statement without saving it', function (): void {
    agcInvoice($this, [['net' => '1000.00']], '2027-03-10');

    $this->postJson('/api/v1/agent-commissions/statements/preview', agcStatementPayload($this, '2027-01-01', '2027-03-31'))
        ->assertOk()
        ->assertJsonPath('data.gross_commission', '100.00')
        ->assertJsonPath('data.lines_count', 1);

    $this->getJson('/api/v1/agent-commissions/statements')->assertOk()->assertJsonCount(0, 'data');
    expect(CommissionEntry::query()->first()->settled_amount)->toEqual('0.00');
});

it('blocks the payout of a cash-basis commission whose collection stops being reconciled', function (): void {
    $this->agent->update(['accrual_basis' => 'collected']);
    $today = now()->toDateString();
    $invoice = agcInvoice($this, [['net' => '1000.00']], $today);
    $line = agcBankLine($this, true, '1220.00');
    agcPayment($this, $invoice, '1220.00', $line);

    $year = (int) now()->year;
    $id = $this->postJson('/api/v1/agent-commissions/statements', agcStatementPayload($this, $year.'-01-01', $year.'-12-31', 'custom'))
        ->assertCreated()->assertJsonPath('data.gross_commission', '100.00')->json('data.id');
    $this->postJson("/api/v1/agent-commissions/statements/{$id}/approve")->assertOk();

    treasuryInTenant($this->tenant, fn () => $line->update(['reconciled' => false]));

    $this->postJson("/api/v1/agent-commissions/statements/{$id}/pay", [])->assertStatus(422)->assertJsonPath('code', 'payout_blocked');
    $this->postJson("/api/v1/agent-commissions/statements/{$id}/void")->assertOk();

    // The statement drawn again has nothing to pay: the commission is locked until the bank reconciles it.
    $this->postJson('/api/v1/agent-commissions/statements', agcStatementPayload($this, $year.'-01-01', $year.'-12-31', 'custom'))->assertStatus(422)->assertJsonPath('code', 'nothing_to_settle');
});

it('leaves the cash-basis commission that waits for the bank out of the statement and reports it as blocked', function (): void {
    $this->agent->update(['accrual_basis' => 'collected']);
    $today = now()->toDateString();
    $year = (int) now()->year;
    $paid = agcInvoice($this, [['net' => '1000.00']], $today);
    agcPayment($this, $paid, '1220.00', agcBankLine($this, true, '1220.00'));
    agcInvoice($this, [['net' => '500.00']], $today); // no collection yet: commission 50 locked

    $this->postJson('/api/v1/agent-commissions/statements', agcStatementPayload($this, $year.'-01-01', $year.'-12-31', 'custom'))
        ->assertCreated()
        ->assertJsonPath('data.gross_commission', '100.00')
        ->assertJsonPath('data.blocked_amount', '50.00');

    $this->getJson('/api/v1/agent-commissions/ledger?accrual_basis=collected')
        ->assertOk()
        ->assertJsonPath('meta.totals.commission', '150.00')
        ->assertJsonPath('meta.totals.blocked', '50.00')
        ->assertJsonPath('meta.totals.settled', '100.00');
});

it('downloads the statement as a PDF and sends it to the agent by email', function (): void {
    Mail::fake();
    agcInvoice($this, [['net' => '1000.00']], '2027-03-10');
    $id = $this->postJson('/api/v1/agent-commissions/statements', agcStatementPayload($this, '2027-01-01', '2027-03-31'))->assertCreated()->json('data.id');

    $pdf = $this->get("/api/v1/agent-commissions/statements/{$id}/pdf")->assertOk();
    expect($pdf->headers->get('Content-Type'))->toBe('application/pdf')->and(substr($pdf->getContent(), 0, 4))->toBe('%PDF');

    $this->postJson("/api/v1/agent-commissions/statements/{$id}/send", ['locale' => 'en'])
        ->assertOk()->assertJsonPath('data.sent_to', 'mario.agente@example.com')->assertJsonPath('data.sent_count', 1);
    Mail::assertSent(CommissionStatementMail::class, fn (CommissionStatementMail $mail): bool => $mail->hasTo('mario.agente@example.com'));

    $this->rep->update(['email' => null]);
    $this->postJson("/api/v1/agent-commissions/statements/{$id}/send", [])->assertStatus(422)->assertJsonPath('code', 'no_recipient');
    $this->postJson("/api/v1/agent-commissions/statements/{$id}/send", ['recipient' => 'altro@example.com'])->assertOk()->assertJsonPath('data.sent_count', 2);
});

it('exports the CU summary of the statements settled in the year and the Enasarco quarterly trace', function (): void {
    $this->agent->update(['tax_code' => 'RSSMRA80A01F205X', 'vat_number' => 'IT01234567890', 'enasarco_registration_number' => 'ENA-123']);
    agcInvoice($this, [['net' => '1000.00']], '2027-03-10');
    $id = $this->postJson('/api/v1/agent-commissions/statements', agcStatementPayload($this, '2027-01-01', '2027-03-31'))->assertCreated()->json('data.id');
    $this->postJson("/api/v1/agent-commissions/statements/{$id}/approve")->assertOk();

    $enasarco = $this->get('/api/v1/agent-commissions/exports/enasarco?year=2027&quarter=1')->assertOk();
    expect($enasarco->headers->get('Content-Type'))->toContain('text/csv')
        ->and($enasarco->getContent())->toContain('ENA-123')->toContain('Mario Agente')->toContain('8.75');

    $this->postJson("/api/v1/agent-commissions/statements/{$id}/pay", ['paid_on' => '2027-04-10'])->assertOk();
    $cu = $this->get('/api/v1/agent-commissions/exports/cu?year=2027')->assertOk()->getContent();
    expect($cu)->toContain('RSSMRA80A01F205X')->toContain(';Q;')->toContain('100.00');
    expect($this->get('/api/v1/agent-commissions/exports/cu?year=2026')->assertOk()->getContent())->not->toContain('RSSMRA80A01F205X');
});
