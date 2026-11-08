<?php

declare(strict_types=1);

use App\Models\BankReconciliationRule;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\GeneralLedger\Models\JournalEntry;

require_once __DIR__.'/TreasurySupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    treasurySetUp($this);
    $this->statement = BankStatement::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'source_format' => 'camt053', 'account_iban' => 'IT60X0542811101000000123456', 'statement_date' => now()->toDateString(), 'currency' => 'EUR', 'total_entries' => 4, 'status' => 'draft']);
});

function ruleLine(object $test, int $number, string $amount, string $direction, array $overrides = []): BankStatementLine
{
    return BankStatementLine::withoutGlobalScopes()->create(array_merge([
        'tenant_id' => $test->tenant->id, 'bank_statement_id' => $test->statement->id, 'line_number' => $number, 'booking_date' => now()->toDateString(), 'value_date' => now()->toDateString(),
        'amount' => $amount, 'direction' => $direction, 'description' => "Line {$number}", 'reconciled' => false,
    ], $overrides));
}

test('reconciliation rules are managed through the API with their validation', function (): void {
    $created = $this->postJson('/api/v1/banking/reconciliation-rules', ['name' => 'Leasing auto', 'priority' => 10, 'direction' => 'debit', 'expected_amount' => '350.00', 'amount_tolerance' => '1.00', 'counterparty_iban' => 'it42 l123 4512 3451 2345 6789 012', 'category' => 'generic_expense', 'ledger_account_code' => '6900'])
        ->assertCreated()->assertJsonPath('data.expected_amount', '350.00')->assertJsonPath('data.priority', 10);
    $ruleId = $created->json('data.id');

    $this->putJson("/api/v1/banking/reconciliation-rules/{$ruleId}", ['priority' => 5, 'is_active' => false])->assertOk()->assertJsonPath('data.priority', 5)->assertJsonPath('data.is_active', false)->assertJsonPath('data.expected_amount', '350.00');
    $this->getJson('/api/v1/banking/reconciliation-rules')->assertOk()->assertJsonCount(1, 'data');

    $this->postJson('/api/v1/banking/reconciliation-rules', ['name' => 'No condition', 'category' => 'bank_expense'])->assertUnprocessable()->assertJsonValidationErrors(['conditions']);
    $this->postJson('/api/v1/banking/reconciliation-rules', ['name' => 'Leasing auto', 'category' => 'invoice', 'direction' => 'sideways', 'ledger_account_code' => '9999', 'max_amount' => '1', 'min_amount' => '5'])->assertUnprocessable()->assertJsonValidationErrors(['name', 'category', 'direction', 'ledger_account_code', 'max_amount']);
    $this->postJson('/api/v1/banking/reconciliation-rules', ['name' => 'Incoming payroll', 'direction' => 'credit', 'description_contains' => 'X', 'category' => 'payroll'])->assertUnprocessable()->assertJsonValidationErrors(['category']);
    $this->postJson('/api/v1/banking/reconciliation-rules', ['name' => 'Foreign account', 'description_contains' => 'X', 'category' => 'payroll', 'ledger_account_code' => treasuryAccount($this->otherTenant, '7777', 'Foreign')->account_code])->assertUnprocessable()->assertJsonValidationErrors(['ledger_account_code']);
    $this->putJson("/api/v1/banking/reconciliation-rules/{$ruleId}", ['expected_amount' => null, 'counterparty_iban' => null])->assertUnprocessable()->assertJsonValidationErrors(['conditions']);

    $foreign = treasuryInTenant($this->otherTenant, fn () => BankReconciliationRule::withoutGlobalScopes()->create(['tenant_id' => $this->otherTenant->id, 'name' => 'Theirs', 'description_contains' => 'X', 'category' => 'bank_expense']));
    $this->putJson("/api/v1/banking/reconciliation-rules/{$foreign->id}", ['priority' => 1])->assertNotFound();
    $this->deleteJson("/api/v1/banking/reconciliation-rules/{$foreign->id}")->assertNotFound();

    $this->deleteJson("/api/v1/banking/reconciliation-rules/{$ruleId}")->assertOk();
    $this->getJson('/api/v1/banking/reconciliation-rules')->assertOk()->assertJsonCount(0, 'data');

    treasuryActor($this, 'accountant_readonly');
    $this->getJson('/api/v1/banking/reconciliation-rules')->assertOk();
    $this->postJson('/api/v1/banking/reconciliation-rules', ['name' => 'RO', 'description_contains' => 'X', 'category' => 'bank_expense'])->assertForbidden();
});

test('the rules engine categorises by priority on amount tolerance, reference, IBAN and counterparty, and posts the lines', function (): void {
    BankReconciliationRule::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Broad fees', 'priority' => 50, 'direction' => 'debit', 'description_contains' => 'commissioni', 'category' => 'bank_expense']);
    BankReconciliationRule::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Leasing', 'priority' => 10, 'direction' => 'debit', 'expected_amount' => '350.00', 'amount_tolerance' => '0.50', 'counterparty_iban' => 'IT42L1234512345123456789012', 'category' => 'generic_expense', 'ledger_account_code' => '6510']);
    BankReconciliationRule::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Savings sweep', 'priority' => 20, 'reference_contains' => 'GIRO-', 'counterparty_name_contains' => 'tesoreria alfa', 'category' => 'internal_transfer']);
    BankReconciliationRule::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Disabled', 'priority' => 1, 'is_active' => false, 'description_contains' => 'commissioni', 'category' => 'payroll']);

    $leasing = ruleLine($this, 1, '350.40', 'debit', ['counterpart_iban' => 'IT42 L123 4512 3451 2345 6789 012', 'description' => 'Canone leasing commissioni incluse']);
    $fees = ruleLine($this, 2, '7.50', 'debit', ['description' => 'COMMISSIONI BONIFICO']);
    $sweep = ruleLine($this, 3, '1000.00', 'credit', ['reference' => 'GIRO-0001', 'counterpart_name' => 'Tesoreria Alfa Srl']);
    $other = ruleLine($this, 4, '351.00', 'debit', ['counterpart_iban' => 'IT42L1234512345123456789012', 'description' => 'Canone leasing']);

    $result = $this->postJson("/api/v1/banking/statements/{$this->statement->id}/reconcile")->assertOk();
    expect($result->json('data.reconciliation'))->toBe(['reconciled' => 3, 'unmatched' => 1]);

    $leasing->refresh();
    $rules = BankReconciliationRule::query()->pluck('id', 'name');
    expect($leasing->reconciliation_category)->toBe('generic_expense')
        ->and($leasing->reconciliation_rule_id)->toBe($rules['Leasing'])
        ->and($leasing->reconciliation_type)->toBe('auto_categorization')
        ->and(treasuryPostings('bank_line_categorization', (string) $leasing->id))->toBe([['6510', '350.40', '0.00'], ['1020', '0.00', '350.40']])
        ->and($fees->refresh()->reconciliation_rule_id)->toBe($rules['Broad fees'])
        ->and(treasuryPostings('bank_line_categorization', (string) $fees->id))->toBe([['6220', '7.50', '0.00'], ['1020', '0.00', '7.50']])
        ->and(treasuryPostings('bank_line_categorization', (string) $sweep->id))->toBe([['1020', '1000.00', '0.00'], ['1090', '0.00', '1000.00']])
        ->and($other->refresh()->reconciled)->toBeFalse();

    // Undoing a rule categorisation reverses its entry.
    $this->postJson("/api/v1/banking/statements/lines/{$fees->id}/unmatch")->assertOk()->assertJsonPath('data.reconciliation_rule_id', null);
    expect(JournalEntry::withoutGlobalScopes()->where('source_document_id', $fees->id)->pluck('status')->sort()->values()->all())->toBe(['posted', 'reversed']);
});

test('a rule whose account is not configured leaves the line open for the next rule', function (): void {
    $this->tenant->update(['settings' => ['preferences' => ['banking' => ['ledger_defaults' => ['generic_expense_account_code' => '6900']]]]]);
    BankReconciliationRule::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Fees without account', 'priority' => 1, 'description_contains' => 'commissioni', 'category' => 'bank_expense']);
    BankReconciliationRule::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Catch all', 'priority' => 2, 'min_amount' => '0', 'category' => 'generic_expense']);

    $line = ruleLine($this, 1, '7.50', 'debit', ['description' => 'COMMISSIONI']);
    $this->postJson("/api/v1/banking/statements/{$this->statement->id}/reconcile")->assertOk();

    expect($line->refresh()->reconciliation_category)->toBe('generic_expense')
        ->and(treasuryPostings('bank_line_categorization', (string) $line->id))->toBe([['6900', '7.50', '0.00'], ['1020', '0.00', '7.50']]);
});
