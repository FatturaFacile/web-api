<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\Treasury\Contracts\BankReconciliationServiceInterface;
use Modules\Treasury\Events\CollectionReceivedEvent;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\BankStatement;
use Modules\Treasury\Models\BankStatementLine;
use Modules\Treasury\Models\PaymentBatch;
use Modules\Treasury\Models\PaymentLine;
use Modules\Treasury\Models\ReceivableCollection;
use Modules\Treasury\Models\ReconciliationMatch;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->tenant = Tenant::first() ?? Tenant::create([
        'id' => (string) Str::uuid7(),
        'name' => 'Treasury Safeguards Tenant',
        'legal_name' => 'Treasury Safeguards SpA',
    ]);
    app(TenantContext::class)->setTenant($this->tenant);
    $this->tenantId = (string) $this->tenant->id;
    $this->reconService = app(BankReconciliationServiceInterface::class);

    $this->bankAccount = BankAccount::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenantId,
        'company_id' => $this->tenantId,
        'bank_name' => 'Banca Safeguards',
        'account_number' => 'ACC-SAFE-01',
        'account_type' => 'OPERATING',
        'iban' => 'IT40S0542811101000000123456',
        'currency' => 'EUR',
        'opening_balance' => 10000.00,
        'current_balance' => 10000.00,
        'available_balance' => 10000.00,
        'is_active' => true,
    ]);

    $this->batch = PaymentBatch::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenantId,
        'company_id' => $this->tenantId,
        'bank_account_id' => $this->bankAccount->id,
        'batch_reference' => 'BATCH-SAFE-'.Str::upper(Str::random(6)),
        'payment_method' => 'SEPA_CREDIT_TRANSFER',
        'execution_date' => now()->toDateString(),
        'currency' => 'EUR',
        'total_amount' => 300.00,
        'item_count' => 1,
        'status' => 'exported',
    ]);
});

function safeguardsPaymentLine(object $test, array $overrides = []): PaymentLine
{
    return PaymentLine::create(array_merge([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $test->tenantId,
        'company_id' => $test->tenantId,
        'payment_batch_id' => $test->batch->id,
        'line_number' => 1,
        'recipient_type' => 'supplier',
        'recipient_id' => (string) Str::uuid7(),
        'recipient_name' => 'Fornitore Safeguards S.r.l.',
        'recipient_iban' => 'IT77D0310401600000000004321',
        'amount' => 300.00,
        'currency' => 'EUR',
        'instruction_id' => 'INSTR-'.Str::upper(Str::random(10)),
        'end_to_end_id' => 'E2E-'.Str::upper(Str::random(12)),
        'remittance_reference' => 'Pagamento fornitore',
        'execution_date' => now()->toDateString(),
        'status' => 'executed',
    ], $overrides));
}

function safeguardsImport(object $test, array $lines, string $date, ?string $statementNumber = null): BankStatement
{
    return $test->reconService->importStatement(array_filter([
        'tenant_id' => $test->tenantId,
        'company_id' => $test->tenantId,
        'bank_account_id' => $test->bankAccount->id,
        'statement_number' => $statementNumber,
        'statement_date' => $date,
        'format' => 'CAMT_053',
    ], fn ($value) => $value !== null), $lines);
}

function safeguardsCustomer(object $test, string $iban): Contact
{
    return Contact::create([
        'tenant_id' => $test->tenantId,
        'type' => 'customer',
        'company_name' => 'Cliente '.Str::random(5),
        'country' => 'IT',
        'iban' => $iban,
    ]);
}

function safeguardsCollection(object $test, string $customerId, float $amount, ?string $invoiceId = null): ReceivableCollection
{
    return ReceivableCollection::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $test->tenantId,
        'company_id' => $test->tenantId,
        'customer_id' => $customerId,
        'invoice_id' => $invoiceId,
        'expected_amount' => $amount,
        'collected_amount' => 0.00,
        'due_date' => now()->addDays(10)->toDateString(),
        'payment_method' => 'bank_transfer',
        'status' => 'pending',
        'aging_bucket' => 'current',
        'dunning_level' => 0,
    ]);
}

test('a payment line cleared by one statement is not matched again by a later statement', function () {
    $paymentLine = safeguardsPaymentLine($this, ['end_to_end_id' => 'E2E-CLEARED-000001']);

    $first = safeguardsImport($this, [[
        'booking_date' => now()->subDays(2)->toDateString(),
        'amount' => -300.00,
        'bank_reference' => 'E2E-CLEARED-000001',
    ]], now()->subDays(2)->toDateString());
    $this->reconService->runAutoReconciliation($first->id);

    expect($paymentLine->refresh()->status)->toBe('cleared');

    $second = safeguardsImport($this, [[
        'booking_date' => now()->toDateString(),
        'amount' => -300.00,
        'bank_reference' => 'E2E-CLEARED-000001',
    ]], now()->toDateString());
    $this->reconService->runAutoReconciliation($second->id);

    expect(BankStatementLine::where('bank_statement_id', $second->id)->first()->reconciled)->toBeFalse()
        ->and(ReconciliationMatch::where('matched_entity_id', $paymentLine->id)->count())->toBe(1);
});

test('a short reference or a LIKE wildcard does not match an unrelated remittance reference', function (string $reference) {
    $paymentLine = safeguardsPaymentLine($this, ['remittance_reference' => 'Fattura 12345 Acme saldo']);

    $statement = safeguardsImport($this, [[
        'booking_date' => now()->toDateString(),
        'amount' => -300.00,
        'bank_reference' => $reference,
    ]], now()->toDateString());
    $this->reconService->runAutoReconciliation($statement->id);

    expect(BankStatementLine::where('bank_statement_id', $statement->id)->first()->reconciled)->toBeFalse()
        ->and($paymentLine->refresh()->status)->toBe('executed');
})->with(['short reference' => '123', 'percent wildcard' => '%', 'underscore wildcards' => '________']);

test('a long reference inside the remittance reference still matches the payment line', function () {
    $paymentLine = safeguardsPaymentLine($this, ['remittance_reference' => 'Saldo RIF-LONG-REFERENCE-99 fornitore']);

    $statement = safeguardsImport($this, [[
        'booking_date' => now()->toDateString(),
        'amount' => -300.00,
        'bank_reference' => 'RIF-LONG-REFERENCE-99',
    ]], now()->toDateString());
    $this->reconService->runAutoReconciliation($statement->id);

    expect(BankStatementLine::where('bank_statement_id', $statement->id)->first()->reconciled)->toBeTrue()
        ->and($paymentLine->refresh()->status)->toBe('cleared');
});

test('an incoming amount from an unknown payer does not settle a collection with the same amount', function () {
    Event::fake([CollectionReceivedEvent::class]);
    $customer = safeguardsCustomer($this, 'IT60X0542811101000000654321');
    $collection = safeguardsCollection($this, (string) $customer->id, 512.40);

    $statement = safeguardsImport($this, [[
        'booking_date' => now()->toDateString(),
        'amount' => 512.40,
        'counterparty_iban' => 'IT02L1234512345123456789012',
        'remittance_information' => 'Bonifico generico',
    ]], now()->toDateString());
    $this->reconService->runAutoReconciliation($statement->id);

    expect($collection->refresh()->status)->toBe('pending')
        ->and(BankStatementLine::where('bank_statement_id', $statement->id)->first()->reconciled)->toBeFalse();
    Event::assertNotDispatched(CollectionReceivedEvent::class);
});

test('an incoming amount from the customer IBAN is booked through the collection service', function () {
    Event::fake([CollectionReceivedEvent::class]);
    $customer = safeguardsCustomer($this, 'IT60X0542811101000000654321');
    $collection = safeguardsCollection($this, (string) $customer->id, 512.40);

    $statement = safeguardsImport($this, [[
        'booking_date' => now()->toDateString(),
        'amount' => 512.40,
        'counterparty_iban' => 'it60 x054 2811 1010 0000 0654 321',
    ]], now()->toDateString());
    $this->reconService->runAutoReconciliation($statement->id);

    $collection->refresh();
    expect($collection->status)->toBe('collected')
        ->and((float) $collection->collected_amount)->toBe(512.40)
        ->and(ReconciliationMatch::where('matched_entity_id', $collection->id)->value('match_type'))->toBe('iban_amount');
    Event::assertDispatched(CollectionReceivedEvent::class, fn (CollectionReceivedEvent $event) => $event->collectionId === (string) $collection->id
        && $event->collectedAmount === 512.40);
});

test('an incoming amount quoting the invoice number settles the collection of that invoice', function () {
    Event::fake([CollectionReceivedEvent::class]);
    $customer = safeguardsCustomer($this, 'IT60X0542811101000000654321');
    $invoice = SalesInvoice::factory()->create([
        'tenant_id' => $this->tenantId,
        'contact_id' => $customer->id,
        'invoice_number' => 'FT-SAFE-'.random_int(10000, 99999),
    ]);
    $otherCollection = safeguardsCollection($this, (string) safeguardsCustomer($this, 'IT11A0306909606100000063397')->id, 777.00);
    $collection = safeguardsCollection($this, (string) $customer->id, 777.00, (string) $invoice->id);

    $statement = safeguardsImport($this, [[
        'booking_date' => now()->toDateString(),
        'amount' => 777.00,
        'remittance_information' => "Saldo fattura {$invoice->invoice_number} del mese",
    ]], now()->toDateString());
    $this->reconService->runAutoReconciliation($statement->id);

    expect($collection->refresh()->status)->toBe('collected')
        ->and($otherCollection->refresh()->status)->toBe('pending')
        ->and(ReconciliationMatch::where('matched_entity_id', $collection->id)->value('match_type'))->toBe('reference_amount');
    Event::assertDispatched(CollectionReceivedEvent::class);
});

test('the same statement imported twice without a statement number is rejected', function () {
    $lines = [[
        'booking_date' => now()->toDateString(),
        'amount' => 125.00,
        'bank_reference' => 'DUP-REF-0001',
        'counterparty_iban' => 'IT60X0542811101000000654321',
    ]];

    safeguardsImport($this, $lines, now()->toDateString());

    expect(fn () => safeguardsImport($this, $lines, now()->toDateString()))->toThrow(ValidationException::class)
        ->and(BankStatement::where('bank_account_id', $this->bankAccount->id)->count())->toBe(1);
});

test('the statement fingerprint ignores the statement number', function () {
    $lines = [['booking_date' => now()->toDateString(), 'amount' => 90.00, 'bank_reference' => 'NUM-REF-0001']];

    safeguardsImport($this, $lines, now()->toDateString(), 'STMT-A');

    expect(fn () => safeguardsImport($this, $lines, now()->toDateString(), 'STMT-B'))->toThrow(ValidationException::class);
});
