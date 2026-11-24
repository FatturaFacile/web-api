<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AgentCommissions\Models\CommissionEntry;

require_once __DIR__.'/AgentCommissionsSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    agcSetUp($this);
    $this->agent = agcAgent($this, null, ['accrual_basis' => 'collected']);
    agcRule($this, 'General', ['rate' => '10']);
});

function agcEntry(string $invoiceId): CommissionEntry
{
    return CommissionEntry::query()->where('invoice_id', $invoiceId)->firstOrFail();
}

it('accrues a cash-basis commission at invoicing but releases nothing until the collection arrives', function (): void {
    $invoice = agcInvoice($this, [['net' => '1000.00']]);
    $entry = agcEntry($invoice->id);

    expect((string) $entry->commission_amount)->toBe('100.00')
        ->and((string) $entry->eligible_amount)->toBe('0.00')
        ->and($entry->status)->toBe('pending_collection');
});

it('keeps a collection that is not reconciled with the bank locked', function (): void {
    $invoice = agcInvoice($this, [['net' => '1000.00']]); // gross 1220
    agcPayment($this, $invoice, '1220.00', null);

    $entry = agcEntry($invoice->id);
    expect((string) $entry->eligible_amount)->toBe('0.00')
        ->and($entry->status)->toBe('awaiting_reconciliation')
        ->and((string) $entry->collected_ratio)->toBe('1.000000')
        ->and((string) $entry->reconciled_ratio)->toBe('0.000000');

    $unreconciledLine = agcBankLine($this, false, '1220.00');
    agcPayment($this, agcInvoice($this, [['net' => '1000.00']], '2027-03-11'), '1220.00', $unreconciledLine);
    expect((string) CommissionEntry::query()->where('status', 'awaiting_reconciliation')->count())->toBe('2');
});

it('releases the commission in proportion to partial collections once each is reconciled, and takes it back when a reconciliation is undone', function (): void {
    $invoice = agcInvoice($this, [['net' => '1000.00']]); // gross 1220, commission 100
    $firstLine = agcBankLine($this, false, '610.00');
    agcPayment($this, $invoice, '610.00', $firstLine);
    expect((string) agcEntry($invoice->id)->eligible_amount)->toBe('0.00');

    treasuryInTenant($this->tenant, fn () => $firstLine->update(['reconciled' => true]));
    $entry = agcEntry($invoice->id);
    expect((string) $entry->eligible_amount)->toBe('50.00')->and($entry->status)->toBe('partially_eligible');

    $secondLine = agcBankLine($this, true, '610.00');
    agcPayment($this, $invoice, '610.00', $secondLine);
    $entry = agcEntry($invoice->id);
    expect((string) $entry->eligible_amount)->toBe('100.00')->and($entry->status)->toBe('eligible');

    treasuryInTenant($this->tenant, fn () => $firstLine->update(['reconciled' => false]));
    $entry = agcEntry($invoice->id);
    expect((string) $entry->eligible_amount)->toBe('50.00')
        ->and($entry->movements()->pluck('kind')->all())->toBe(['release', 'release', 'reversal']);
});

it('measures the collection against what is still due after a credit note', function (): void {
    $invoice = agcInvoice($this, [['net' => '1000.00']]); // gross 1220
    agcInvoice($this, [['net' => '500.00']], '2027-03-15', ['invoice_type' => 'credit_note', 'related_invoice_id' => $invoice->id, 'sales_rep_id' => null]); // gross 610 credited

    $line = agcBankLine($this, true, '610.00');
    agcPayment($this, $invoice, '610.00', $line); // pays the whole 610 still due

    $entry = agcEntry($invoice->id);
    expect((string) $entry->credited_amount)->toBe('50.00')->and((string) $entry->eligible_amount)->toBe('50.00')->and($entry->status)->toBe('eligible');
});

it('does not lock an invoiced-basis commission', function (): void {
    $this->agent->update(['accrual_basis' => 'invoiced']);
    $invoice = agcInvoice($this, [['net' => '1000.00']], '2027-03-12');

    expect((string) agcEntry($invoice->id)->eligible_amount)->toBe('100.00');
});

it('lets a rule override the accrual basis of the agent', function (): void {
    $this->agent->update(['accrual_basis' => 'invoiced']);
    agcRule($this, 'Cash only', ['rate' => '10', 'priority' => 1, 'accrual_basis' => 'collected']);
    $invoice = agcInvoice($this, [['net' => '1000.00']], '2027-03-13');

    expect(agcEntry($invoice->id)->accrual_basis)->toBe('collected')->and((string) agcEntry($invoice->id)->eligible_amount)->toBe('0.00');
});

it('re-reads open commissions with the sync endpoint', function (): void {
    $invoice = agcInvoice($this, [['net' => '1000.00']]);
    $line = agcBankLine($this, true, '1220.00');
    agcPayment($this, $invoice, '1220.00', $line);
    CommissionEntry::query()->where('invoice_id', $invoice->id)->update(['eligible_amount' => '0', 'status' => 'pending_collection', 'collected_ratio' => '0', 'reconciled_ratio' => '0']);

    $this->postJson('/api/v1/agent-commissions/sync', [])->assertOk()->assertJsonPath('data.open_invoices', 1);

    expect((string) agcEntry($invoice->id)->eligible_amount)->toBe('100.00');
});
