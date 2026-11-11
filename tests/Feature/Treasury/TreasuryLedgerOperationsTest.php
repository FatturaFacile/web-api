<?php

declare(strict_types=1);

use App\Models\BankStatement;
use App\Models\ExchangeRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Models\JournalLine;
use Modules\GeneralLedger\Services\FiscalPeriodResolver;
use Modules\Treasury\Models\BankTransaction;
use Modules\Treasury\Models\CashRegister;
use Modules\Treasury\Models\FxRevaluation;
use Modules\Treasury\Models\TreasuryBankMovement;

require_once __DIR__.'/TreasurySupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    treasurySetUp($this);
    $this->intesa = treasuryBank($this->tenant, 'IT60X0542811101000000123456', $this->accounts['1001']);
    $this->sella = treasuryBank($this->tenant, 'IT42L1234512345123456789012', $this->accounts['1003']);
    $this->today = now()->toDateString();
});

// ---------------------------------------------------------------------------------------------------------------
// Internal transfers
// ---------------------------------------------------------------------------------------------------------------

test('an internal transfer is posted on both bank accounts, moves both balances and is idempotent', function (): void {
    $payload = ['from_bank_account_id' => $this->intesa->id, 'to_bank_account_id' => $this->sella->id, 'amount' => '2500.50', 'transfer_date' => $this->today, 'idempotency_key' => 'TR-1'];

    $created = $this->postJson('/api/v1/treasury/transfers', $payload)->assertCreated()
        ->assertJsonPath('data.movement_type', 'transfer')
        ->assertJsonPath('data.amount', '2500.50');
    $movementId = $created->json('data.id');

    expect(treasuryPostings('treasury_transfer', $movementId))->toBe([['1003', '2500.50', '0.00'], ['1001', '0.00', '2500.50']])
        ->and((string) $this->intesa->refresh()->current_balance)->toBe('7499.50')
        ->and((string) $this->sella->refresh()->current_balance)->toBe('12500.50')
        ->and(BankTransaction::withoutGlobalScopes()->where('reference', $created->json('data.journal_entry_id'))->pluck('amount')->map(fn ($amount): string => (string) $amount)->sort()->values()->all())->toBe(['-2500.50', '2500.50']);

    // A retry with the same key returns the same transfer and posts nothing more.
    $this->postJson('/api/v1/treasury/transfers', $payload)->assertOk()->assertJsonPath('data.id', $movementId);
    expect(TreasuryBankMovement::query()->count())->toBe(1)
        ->and(JournalEntry::withoutGlobalScopes()->where('source_document_type', 'treasury_transfer')->count())->toBe(1)
        ->and((string) $this->intesa->refresh()->current_balance)->toBe('7499.50');

    $this->getJson('/api/v1/treasury/movements?movement_type=transfer')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1);
});

test('transfers are validated and refused without a ledger account or across currencies', function (): void {
    $unlinked = treasuryBank($this->tenant, 'IT84S0542811101000000654321', null);
    $swiss = treasuryBank($this->tenant, 'CH9300762011623852957', $this->accounts['1002'], ['currency' => 'CHF']);
    $foreign = treasuryBank($this->otherTenant, 'IT80N0306909606100000063275', null);

    $this->postJson('/api/v1/treasury/transfers', [])->assertUnprocessable()->assertJsonValidationErrors(['from_bank_account_id', 'to_bank_account_id', 'amount', 'transfer_date', 'idempotency_key']);
    $this->postJson('/api/v1/treasury/transfers', ['from_bank_account_id' => $this->intesa->id, 'to_bank_account_id' => $this->intesa->id, 'amount' => '-1', 'transfer_date' => $this->today, 'idempotency_key' => 'X'])->assertUnprocessable()->assertJsonValidationErrors(['to_bank_account_id', 'amount']);
    $this->postJson('/api/v1/treasury/transfers', ['from_bank_account_id' => $this->intesa->id, 'to_bank_account_id' => $foreign->id, 'amount' => '1', 'transfer_date' => $this->today, 'idempotency_key' => 'X'])->assertUnprocessable()->assertJsonValidationErrors(['to_bank_account_id']);
    $this->postJson('/api/v1/treasury/transfers', ['from_bank_account_id' => $this->intesa->id, 'to_bank_account_id' => $this->sella->id, 'amount' => '1.001', 'transfer_date' => $this->today, 'idempotency_key' => 'X'])->assertUnprocessable()->assertJsonValidationErrors(['amount']);

    $this->postJson('/api/v1/treasury/transfers', ['from_bank_account_id' => $this->intesa->id, 'to_bank_account_id' => $unlinked->id, 'amount' => '10', 'transfer_date' => $this->today, 'idempotency_key' => 'U'])->assertUnprocessable()->assertJsonValidationErrors(['to_bank_account_id']);
    $this->postJson('/api/v1/treasury/transfers', ['from_bank_account_id' => $this->intesa->id, 'to_bank_account_id' => $swiss->id, 'amount' => '10', 'transfer_date' => $this->today, 'idempotency_key' => 'C'])->assertUnprocessable()->assertJsonValidationErrors(['to_bank_account_id']);
    $this->postJson('/api/v1/treasury/transfers', ['from_bank_account_id' => $this->intesa->id, 'to_bank_account_id' => $this->sella->id, 'amount' => '10', 'transfer_date' => now()->subYears(3)->toDateString(), 'idempotency_key' => 'P'])->assertUnprocessable()->assertJsonValidationErrors(['date']);

    expect(TreasuryBankMovement::query()->count())->toBe(0)->and((string) $this->intesa->refresh()->current_balance)->toBe('10000.00');
});

test('the intercompany transfer of the liquidity routes is posted on both ledger accounts', function (): void {
    $this->postJson('/api/v1/treasury/liquidity/intercompany-transfer', ['source_bank_account_id' => $this->intesa->id, 'destination_bank_account_id' => $this->sella->id, 'amount' => '100.00', 'transfer_date' => $this->today])
        ->assertCreated();

    $entry = JournalEntry::withoutGlobalScopes()->where('source_document_type', 'treasury_transfer')->firstOrFail();
    expect(treasuryPostings('treasury_transfer', (string) $entry->source_document_id))->toBe([['1003', '100.00', '0.00'], ['1001', '0.00', '100.00']])
        ->and((string) $this->intesa->refresh()->current_balance)->toBe('9900.00');

    $this->postJson('/api/v1/treasury/liquidity/intercompany-transfer', ['source_bank_account_id' => $this->intesa->id, 'destination_bank_account_id' => treasuryBank($this->otherTenant, 'IT80N0306909606100000063275', null)->id, 'amount' => '1'])
        ->assertUnprocessable()->assertJsonValidationErrors(['destination_bank_account_id']);
});

// ---------------------------------------------------------------------------------------------------------------
// Bank fees and interest
// ---------------------------------------------------------------------------------------------------------------

test('bank fees and interest are posted on the configured accounts and move the balance', function (): void {
    $fee = $this->postJson("/api/v1/treasury/bank-accounts/{$this->intesa->id}/charges", ['movement_type' => 'bank_fee', 'amount' => '12.50', 'movement_date' => $this->today, 'idempotency_key' => 'FEE-1'])->assertCreated()->json('data.id');
    $income = $this->postJson("/api/v1/treasury/bank-accounts/{$this->intesa->id}/charges", ['movement_type' => 'interest_income', 'amount' => '3.20', 'movement_date' => $this->today, 'idempotency_key' => 'INT-1'])->assertCreated()->json('data.id');
    $expense = $this->postJson("/api/v1/treasury/bank-accounts/{$this->intesa->id}/charges", ['movement_type' => 'interest_expense', 'amount' => '40.00', 'movement_date' => $this->today, 'idempotency_key' => 'INT-2'])->assertCreated()->json('data.id');

    expect(treasuryPostings('treasury_bank_fee', $fee))->toBe([['6220', '12.50', '0.00'], ['1001', '0.00', '12.50']])
        ->and(treasuryPostings('treasury_interest_income', $income))->toBe([['1001', '3.20', '0.00'], ['4810', '0.00', '3.20']])
        ->and(treasuryPostings('treasury_interest_expense', $expense))->toBe([['6510', '40.00', '0.00'], ['1001', '0.00', '40.00']])
        ->and((string) $this->intesa->refresh()->current_balance)->toBe('9950.70');

    $this->postJson("/api/v1/treasury/bank-accounts/{$this->intesa->id}/charges", ['movement_type' => 'bank_fee', 'amount' => '12.50', 'movement_date' => $this->today, 'idempotency_key' => 'FEE-1'])->assertOk()->assertJsonPath('data.id', $fee);
    expect((string) $this->intesa->refresh()->current_balance)->toBe('9950.70');
});

test('a charge without its configured account is refused and nothing is posted', function (): void {
    $this->tenant->update(['settings' => ['preferences' => ['banking' => ['ledger_defaults' => ['bank_charges_account_code' => '6220']]]]]);

    $this->postJson("/api/v1/treasury/bank-accounts/{$this->intesa->id}/charges", ['movement_type' => 'interest_income', 'amount' => '1.00', 'movement_date' => $this->today, 'idempotency_key' => 'I'])
        ->assertUnprocessable()->assertJsonValidationErrors(['movement_type'])
        ->assertJsonPath('message', 'Configure the ledger account interest_income_account_code in the banking preferences (banking.ledger_defaults) before posting this movement.');

    $this->tenant->update(['settings' => ['preferences' => ['banking' => ['ledger_defaults' => ['bank_charges_account_code' => '9999']]]]]);
    $this->postJson("/api/v1/treasury/bank-accounts/{$this->intesa->id}/charges", ['movement_type' => 'bank_fee', 'amount' => '1.00', 'movement_date' => $this->today, 'idempotency_key' => 'F'])->assertUnprocessable();

    $this->postJson("/api/v1/treasury/bank-accounts/{$this->intesa->id}/charges", ['movement_type' => 'cashback', 'amount' => '0'])->assertUnprocessable()->assertJsonValidationErrors(['movement_type', 'amount', 'movement_date', 'idempotency_key']);
    $foreign = treasuryBank($this->otherTenant, 'IT80N0306909606100000063275', null);
    $this->postJson("/api/v1/treasury/bank-accounts/{$foreign->id}/charges", ['movement_type' => 'bank_fee', 'amount' => '1.00', 'movement_date' => $this->today, 'idempotency_key' => 'Z'])->assertNotFound();

    expect(TreasuryBankMovement::query()->count())->toBe(0)->and(JournalEntry::withoutGlobalScopes()->count())->toBe(0);
});

// ---------------------------------------------------------------------------------------------------------------
// Cash register (prima nota cassa)
// ---------------------------------------------------------------------------------------------------------------

test('the cash book posts receipts and payments, refuses an overdraft and closes the day with its difference', function (): void {
    $register = $this->postJson('/api/v1/treasury/cash-registers', ['code' => 'CASSA', 'name' => 'Cassa sede', 'currency' => 'eur', 'gl_account_id' => $this->accounts['1010']->id, 'opening_balance' => '100.00'])
        ->assertCreated()->assertJsonPath('data.currency', 'EUR')->assertJsonPath('data.current_balance', '100.00')->json('data.id');

    $receipt = $this->postJson("/api/v1/treasury/cash-registers/{$register}/movements", ['movement_date' => $this->today, 'direction' => 'in', 'amount' => '50.00', 'counter_account_id' => $this->accounts['1090']->id, 'description' => 'Prelievo banca', 'idempotency_key' => 'C-1'])->assertCreated()->json('data.id');
    $payment = $this->postJson("/api/v1/treasury/cash-registers/{$register}/movements", ['movement_date' => $this->today, 'direction' => 'out', 'payment_method' => 'cheque', 'amount' => '30.00', 'counter_account_id' => $this->accounts['6900']->id, 'description' => 'Cancelleria', 'idempotency_key' => 'C-2'])->assertCreated()->json('data.id');

    expect(treasuryPostings('treasury_cash_movement', $receipt))->toBe([['1010', '50.00', '0.00'], ['1090', '0.00', '50.00']])
        ->and(treasuryPostings('treasury_cash_movement', $payment))->toBe([['6900', '30.00', '0.00'], ['1010', '0.00', '30.00']]);
    $this->getJson("/api/v1/treasury/cash-registers/{$register}")->assertOk()->assertJsonPath('data.current_balance', '120.00');
    $this->getJson("/api/v1/treasury/cash-registers/{$register}/movements")->assertOk()->assertJsonCount(2, 'data');

    // Idempotent retry, and no payment above the cash held.
    $this->postJson("/api/v1/treasury/cash-registers/{$register}/movements", ['movement_date' => $this->today, 'direction' => 'in', 'amount' => '50.00', 'counter_account_id' => $this->accounts['1090']->id, 'description' => 'Prelievo banca', 'idempotency_key' => 'C-1'])->assertOk()->assertJsonPath('data.id', $receipt);
    $this->postJson("/api/v1/treasury/cash-registers/{$register}/movements", ['movement_date' => $this->today, 'direction' => 'out', 'amount' => '500.00', 'counter_account_id' => $this->accounts['6900']->id, 'description' => 'Too much', 'idempotency_key' => 'C-3'])->assertUnprocessable()->assertJsonValidationErrors(['amount']);

    // Counted 118.00 against 120.00: the 2.00 shortage is posted on the cash difference account.
    $closing = $this->postJson("/api/v1/treasury/cash-registers/{$register}/close", ['closing_date' => $this->today, 'counted_balance' => '118.00'])->assertCreated()
        ->assertJsonPath('data.opening_balance', '100.00')->assertJsonPath('data.total_in', '50.00')->assertJsonPath('data.total_out', '30.00')
        ->assertJsonPath('data.expected_balance', '120.00')->assertJsonPath('data.difference', '-2.00')->json('data.id');
    expect(treasuryPostings('treasury_cash_difference', $closing))->toBe([['6995', '2.00', '0.00'], ['1010', '0.00', '2.00']]);

    $this->getJson("/api/v1/treasury/cash-registers/{$register}")->assertOk()->assertJsonPath('data.current_balance', '118.00')->assertJsonPath('data.last_closed_date', $this->today);
    $this->getJson("/api/v1/treasury/cash-registers/{$register}/closings")->assertOk()->assertJsonCount(1, 'data');

    // The closed day is frozen.
    $this->postJson("/api/v1/treasury/cash-registers/{$register}/movements", ['movement_date' => $this->today, 'direction' => 'in', 'amount' => '1.00', 'counter_account_id' => $this->accounts['1090']->id, 'description' => 'Late', 'idempotency_key' => 'C-4'])->assertUnprocessable()->assertJsonValidationErrors(['movement_date']);
    $this->postJson("/api/v1/treasury/cash-registers/{$register}/close", ['closing_date' => $this->today, 'counted_balance' => '118.00'])->assertUnprocessable();
});

test('cash registers are validated, isolated and refuse a difference without its account', function (): void {
    $this->postJson('/api/v1/treasury/cash-registers', ['code' => 'X', 'name' => 'X', 'currency' => 'EUR', 'gl_account_id' => treasuryAccount($this->otherTenant, '1010', 'Foreign cash')->id])->assertUnprocessable()->assertJsonValidationErrors(['gl_account_id']);
    $this->postJson('/api/v1/treasury/cash-registers', [])->assertUnprocessable()->assertJsonValidationErrors(['code', 'name', 'currency', 'gl_account_id']);

    $register = $this->postJson('/api/v1/treasury/cash-registers', ['code' => 'CASSA', 'name' => 'Cassa', 'currency' => 'EUR', 'gl_account_id' => $this->accounts['1010']->id])->assertCreated()->json('data.id');
    $this->postJson('/api/v1/treasury/cash-registers', ['code' => 'CASSA', 'name' => 'Again', 'currency' => 'EUR', 'gl_account_id' => $this->accounts['1010']->id])->assertUnprocessable()->assertJsonValidationErrors(['code']);
    $this->postJson("/api/v1/treasury/cash-registers/{$register}/movements", ['movement_date' => now()->addDay()->toDateString(), 'direction' => 'sideways', 'amount' => '0', 'counter_account_id' => 'nope', 'description' => '', 'idempotency_key' => ''])
        ->assertUnprocessable()->assertJsonValidationErrors(['movement_date', 'direction', 'amount', 'counter_account_id', 'description', 'idempotency_key']);

    $this->tenant->update(['settings' => ['preferences' => ['banking' => ['ledger_defaults' => []]]]]);
    $this->postJson("/api/v1/treasury/cash-registers/{$register}/close", ['closing_date' => $this->today, 'counted_balance' => '5.00'])->assertUnprocessable()->assertJsonValidationErrors(['counted_balance']);
    $this->postJson("/api/v1/treasury/cash-registers/{$register}/close", ['closing_date' => $this->today, 'counted_balance' => '0'])->assertCreated()->assertJsonPath('data.difference', '0.00')->assertJsonPath('data.difference_journal_entry_id', null);

    $foreign = treasuryInTenant($this->otherTenant, fn () => CashRegister::withoutGlobalScopes()->create(['tenant_id' => $this->otherTenant->id, 'code' => 'B', 'name' => 'B', 'currency' => 'EUR', 'gl_account_id' => $this->accounts['1010']->id, 'opening_balance' => 0, 'current_balance' => 0, 'is_active' => true]));
    $this->getJson("/api/v1/treasury/cash-registers/{$foreign->id}")->assertNotFound();
    $this->postJson("/api/v1/treasury/cash-registers/{$foreign->id}/close", ['closing_date' => $this->today, 'counted_balance' => '0'])->assertNotFound();
    $this->getJson('/api/v1/treasury/cash-registers')->assertOk()->assertJsonCount(1, 'data');
});

// ---------------------------------------------------------------------------------------------------------------
// FX revaluation
// ---------------------------------------------------------------------------------------------------------------

test('the period-end FX revaluation posts the unrealised difference and refuses without a rate', function (): void {
    $swiss = treasuryBank($this->tenant, 'CH9300762011623852957', $this->accounts['1002'], ['currency' => 'CHF', 'current_balance' => '1000.00']);
    $date = now()->startOfMonth()->toDateString();

    // 1,000 CHF in the books at 1,052.63 EUR (rate 1.05263 EUR per CHF), as a foreign-currency posting does.
    $deposit = JournalEntry::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'company_id' => $this->tenant->id, 'fiscal_period_id' => app(FiscalPeriodResolver::class)->forDate((string) $this->tenant->id, $date)->id, 'entry_number' => 'DEP-1', 'posting_date' => $date, 'document_date' => $date, 'description' => 'CHF deposit', 'status' => 'posted', 'total_amount' => '1000']);
    JournalLine::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'journal_entry_id' => $deposit->id, 'posting_date' => $date, 'line_number' => 1, 'account_id' => $this->accounts['1002']->id, 'description' => 'Deposit', 'debit_amount' => '1000.00', 'credit_amount' => '0', 'currency' => 'CHF', 'exchange_rate' => '1.052630', 'amount_in_functional_currency' => '1052.63']);
    JournalLine::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'journal_entry_id' => $deposit->id, 'posting_date' => $date, 'line_number' => 2, 'account_id' => $this->accounts['1090']->id, 'description' => 'Deposit', 'debit_amount' => '0', 'credit_amount' => '1052.63', 'currency' => 'EUR', 'exchange_rate' => '1', 'amount_in_functional_currency' => '1052.63']);

    $this->postJson('/api/v1/treasury/fx/revalue', ['period_end_date' => $date])->assertUnprocessable()->assertJsonValidationErrors(['period_end_date']);
    $this->postJson('/api/v1/treasury/fx/revalue', ['period_end_date' => $date, 'spot_rate' => 1.2])->assertUnprocessable()->assertJsonValidationErrors(['spot_rate']);

    // Closing rate 0.94 CHF per EUR: 1,000 CHF are worth 1,063.83 EUR, an unrealised gain of 11.20 EUR.
    ExchangeRate::createGlobal(['source' => 'EUR', 'target' => 'CHF', 'rate' => '0.940000', 'effective_date' => $date]);
    $result = $this->postJson('/api/v1/treasury/fx/revalue', ['period_end_date' => $date])->assertCreated()->json('data');

    expect($result)->toHaveCount(1)
        ->and($result[0])->toMatchArray(['bank_account_id' => $swiss->id, 'currency' => 'CHF', 'foreign_amount' => '1000.00', 'book_value_base' => '1052.63', 'revalued_market_base' => '1063.83', 'unrealized_gain_loss' => '11.20'])
        ->and(treasuryPostings('treasury_fx_revaluation', $result[0]['id']))->toBe([['1002', '11.20', '0.00'], ['4890', '0.00', '11.20']])
        ->and((string) $swiss->refresh()->current_balance)->toBe('1000.00');

    // Once per account and period end; a later rate drop on the next period is a loss against the revalued book.
    $this->postJson('/api/v1/treasury/fx/revalue', ['period_end_date' => $date])->assertCreated()->assertJsonPath('data.0.id', $result[0]['id']);
    expect(FxRevaluation::query()->count())->toBe(1);

    $next = now()->startOfMonth()->addDays(1)->toDateString();
    ExchangeRate::createGlobal(['source' => 'EUR', 'target' => 'CHF', 'rate' => '0.960000', 'effective_date' => $next]);
    $loss = $this->postJson('/api/v1/treasury/fx/revalue', ['period_end_date' => $next, 'currency' => 'CHF'])->assertCreated()->json('data.0');
    expect($loss['book_value_base'])->toBe('1063.83')->and($loss['revalued_market_base'])->toBe('1041.67')->and($loss['unrealized_gain_loss'])->toBe('-22.16')
        ->and(treasuryPostings('treasury_fx_revaluation', $loss['id']))->toBe([['6890', '22.16', '0.00'], ['1002', '0.00', '22.16']]);

    $this->postJson('/api/v1/treasury/fx/revalue', ['period_end_date' => $date, 'currency' => 'USD'])->assertUnprocessable()->assertJsonValidationErrors(['currency']);
});

// ---------------------------------------------------------------------------------------------------------------
// Balances per date
// ---------------------------------------------------------------------------------------------------------------

test('balances per date compare the book balance with the bank statement', function (): void {
    $this->postJson('/api/v1/treasury/transfers', ['from_bank_account_id' => $this->intesa->id, 'to_bank_account_id' => $this->sella->id, 'amount' => '300.00', 'transfer_date' => $this->today, 'idempotency_key' => 'B-1'])->assertCreated();
    BankStatement::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'source_format' => 'camt053', 'account_iban' => $this->intesa->iban, 'statement_date' => $this->today, 'opening_balance' => '0', 'closing_balance' => '-280.00', 'currency' => 'EUR', 'total_entries' => 0, 'status' => 'draft']);

    $balances = collect($this->getJson("/api/v1/treasury/balances?date={$this->today}")->assertOk()->json('data'))->keyBy('bank_account_id');
    expect($balances[$this->intesa->id])->toMatchArray(['book_balance' => '-300.00', 'statement_balance' => '-280.00', 'current_balance' => '9700.00'])
        ->and($balances[$this->sella->id]['book_balance'])->toBe('300.00');

    $yesterday = now()->subDay()->toDateString();
    expect(collect($this->getJson("/api/v1/treasury/balances?date={$yesterday}")->json('data'))->firstWhere('bank_account_id', $this->intesa->id)['book_balance'])->toBe('0.00');

    $history = $this->getJson("/api/v1/treasury/bank-accounts/{$this->intesa->id}/balances?from_date={$yesterday}&to_date={$this->today}")->assertOk()->json('data');
    expect($history['opening_balance'])->toBe('0.00')->and($history['days'])->toBe([['date' => $this->today, 'debit' => '0.00', 'credit' => '300.00', 'balance' => '-300.00']]);

    $this->getJson('/api/v1/treasury/balances?date=31/12/2026')->assertUnprocessable();
    $this->getJson("/api/v1/treasury/bank-accounts/{$this->intesa->id}/balances?from_date=2024-01-01&to_date=2026-01-01")->assertUnprocessable()->assertJsonValidationErrors(['to_date']);
    $this->getJson('/api/v1/treasury/bank-accounts/'.treasuryBank($this->otherTenant, 'IT80N0306909606100000063275', null)->id.'/balances')->assertNotFound();

    $position = $this->getJson('/api/v1/treasury/positions/cash')->assertOk()->json('data');
    expect($position['totals'])->toBe([['currency' => 'EUR', 'bank' => '20000.00', 'cash' => '0.00', 'total' => '20000.00']]);
});

// ---------------------------------------------------------------------------------------------------------------
// Access
// ---------------------------------------------------------------------------------------------------------------

test('read-only roles can read the new treasury records but not write them, other roles see nothing', function (): void {
    treasuryActor($this, 'accountant_readonly');

    $this->getJson('/api/v1/treasury/movements')->assertOk();
    $this->getJson('/api/v1/treasury/cash-registers')->assertOk();
    $this->getJson('/api/v1/treasury/balances')->assertOk();
    $this->postJson('/api/v1/treasury/transfers', ['from_bank_account_id' => $this->intesa->id, 'to_bank_account_id' => $this->sella->id, 'amount' => '1', 'transfer_date' => $this->today, 'idempotency_key' => 'RO'])->assertForbidden();
    $this->postJson("/api/v1/treasury/bank-accounts/{$this->intesa->id}/charges", ['movement_type' => 'bank_fee', 'amount' => '1', 'movement_date' => $this->today, 'idempotency_key' => 'RO'])->assertForbidden();
    $this->postJson('/api/v1/treasury/cash-registers', ['code' => 'RO', 'name' => 'RO', 'currency' => 'EUR', 'gl_account_id' => $this->accounts['1010']->id])->assertForbidden();
    $this->postJson('/api/v1/treasury/fx/revalue', ['period_end_date' => $this->today])->assertForbidden();

    treasuryActor($this, 'sales');
    $this->getJson('/api/v1/treasury/movements')->assertForbidden();

    expect(TreasuryBankMovement::query()->count())->toBe(0)->and(CashRegister::withoutGlobalScopes()->count())->toBe(0);
});
