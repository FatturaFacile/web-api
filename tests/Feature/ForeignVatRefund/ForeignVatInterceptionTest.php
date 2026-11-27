<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\PassiveInvoice;
use App\Models\PassiveInvoiceLine;
use App\Services\Tenancy\TenantContext;
use Database\Seeders\ForeignVatRefundReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\ExpenseManagement\Events\ExpenseReportApprovedEvent;
use Modules\ExpenseManagement\Events\ExpenseReportPostedEvent;
use Modules\ForeignVatRefund\Listeners\InterceptForeignVat;
use Modules\ForeignVatRefund\Models\ForeignVatLedgerEntry;
use Modules\ForeignVatRefund\Services\ForeignVatAccounts;
use Modules\GeneralLedger\Events\PassiveInvoicePostedEvent;

require_once __DIR__.'/ForeignVatSupport.php';
require_once __DIR__.'/../ExpenseManagement/ExpenseManagementSupport.php';

uses(RefreshDatabase::class);

test('the foreign VAT is split by the matrix and the recoverable part is parked on the transitory receivable', function (): void {
    fvSetUp($this);

    $response = fvManual($this, ['supplier_country_code' => 'FR', 'expense_category_code' => 1, 'net_amount' => 1000, 'foreign_vat_rate' => 20, 'foreign_vat_amount' => 200])->assertCreated();

    $response->assertJsonPath('data.eligible_percentage', '80.00')
        ->assertJsonPath('data.recoverable_vat_amount', '160.00')
        ->assertJsonPath('data.non_recoverable_vat_amount', '40.00')
        ->assertJsonPath('data.status', 'ELIGIBLE_ACCUMULATING')
        ->assertJsonPath('data.fiscal_year', 2026)
        ->assertJsonPath('data.fiscal_quarter', 1);

    $entry = ForeignVatLedgerEntry::query()->findOrFail($response->json('data.id'));
    $journal = fvJournal($entry->journal_entry_id);
    $receivable = app(ForeignVatAccounts::class)->receivable((string) $this->tenant->id)->id;
    $cost = app(ForeignVatAccounts::class)->nonRecoverableExpense((string) $this->tenant->id)->id;

    expect($entry->transitory_account_id)->toBe($receivable)
        ->and($journal['debit'])->toBe(200.0)->and($journal['credit'])->toBe(200.0)
        ->and($journal['lines'][$receivable])->toBe(['debit' => 160.0, 'credit' => 0.0])
        ->and($journal['lines'][$cost])->toBe(['debit' => 40.0, 'credit' => 0.0])
        ->and($journal['lines'][(string) $this->inputVat->id])->toBe(['debit' => 0.0, 'credit' => 200.0]);
});

test('a category without deduction stays a cost and nothing is parked on the receivable', function (): void {
    fvSetUp($this);

    $response = fvManual($this, ['supplier_country_code' => 'FR', 'expense_category_code' => 7, 'foreign_vat_amount' => 100, 'net_amount' => 500, 'foreign_vat_rate' => 20])->assertCreated();

    $response->assertJsonPath('data.recoverable_vat_amount', '0.00')->assertJsonPath('data.status', 'NON_RECOVERABLE_EXPENSED');
    $journal = fvJournal(ForeignVatLedgerEntry::query()->findOrFail($response->json('data.id'))->journal_entry_id);

    expect($journal['lines'][app(ForeignVatAccounts::class)->nonRecoverableExpense((string) $this->tenant->id)->id])->toBe(['debit' => 100.0, 'credit' => 0.0]);
});

test('VAT of the own country is not a foreign VAT item', function (): void {
    fvSetUp($this);

    fvManual($this, ['supplier_country_code' => 'IT'])->assertUnprocessable()->assertJsonPath('code', 'not_a_foreign_vat_item');
    expect(ForeignVatLedgerEntry::query()->count())->toBe(0);
});

test('a foreign VAT in a foreign currency is carried in the accounting currency at the stated rate', function (): void {
    fvSetUp($this);

    $response = fvManual($this, ['supplier_country_code' => 'CH', 'expense_category_code' => 6, 'currency_code' => 'CHF', 'net_amount' => 10000, 'foreign_vat_rate' => 3.8, 'foreign_vat_amount' => 380, 'exchange_rate_to_base' => 1.05])->assertCreated();

    $response->assertJsonPath('data.recoverable_vat_amount', '380.00')->assertJsonPath('data.base_currency_foreign_vat_amount', '399.00')->assertJsonPath('data.base_recoverable_vat_amount', '399.00');
    expect(fvJournal(ForeignVatLedgerEntry::query()->findOrFail($response->json('data.id'))->journal_entry_id)['debit'])->toBe(399.0);
});

test('the split preview answers without recording anything', function (): void {
    fvSetUp($this);

    $this->postJson(FVR_API.'/preview-split', ['country' => 'ES', 'expense_category_code' => 1, 'vat_amount' => 100])->assertOk()
        ->assertJsonPath('data.applies', true)
        ->assertJsonPath('data.deductible_percentage', '50.00')
        ->assertJsonPath('data.recoverable_vat_amount', '50.00')
        ->assertJsonPath('data.non_recoverable_vat_amount', '50.00');
    $this->postJson(FVR_API.'/preview-split', ['country' => 'IT', 'expense_category_code' => 1, 'vat_amount' => 100])->assertOk()
        ->assertJsonPath('data.applies', false)->assertJsonPath('data.reason', 'domestic');
    $this->postJson(FVR_API.'/preview-split', ['country' => 'DE', 'description' => 'Hotel accommodation', 'vat_amount' => 19])->assertOk()
        ->assertJsonPath('data.expense_category_code', 6);
    $this->postJson(FVR_API.'/preview-split', ['country' => 'JP', 'expense_category_code' => 1, 'vat_amount' => 10])->assertOk()
        ->assertJsonPath('data.reason', 'no_rule')->assertJsonPath('data.recoverable_vat_amount', '0.00');

    expect(ForeignVatLedgerEntry::query()->count())->toBe(0);
});

test('changing the expense category recalculates the line and books the difference', function (): void {
    fvSetUp($this);
    $entry = fvEntry($this, ['supplier_country_code' => 'FR', 'expense_category_code' => 6, 'foreign_vat_amount' => 200, 'net_amount' => 1000, 'foreign_vat_rate' => 20]);
    $first = $entry->journal_entry_id;

    $this->patchJson(FVR_API.'/ledger/'.$entry->id, ['expense_category_code' => 1])->assertOk()
        ->assertJsonPath('data.eligible_percentage', '80.00')
        ->assertJsonPath('data.recoverable_vat_amount', '160.00');

    $entry->refresh();
    expect($entry->journal_entry_id)->not->toBe($first)
        ->and(fvJournal($entry->journal_entry_id)['lines'][app(ForeignVatAccounts::class)->receivable((string) $this->tenant->id)->id]['debit'])->toBe(160.0);
});

test('the ledger lists, filters and keeps the companies apart', function (): void {
    fvSetUp($this);
    fvEntry($this, ['document_number' => 'DE-1', 'supplier_country_code' => 'DE']);
    fvEntry($this, ['document_number' => 'FR-1', 'supplier_country_code' => 'FR', 'expense_category_code' => 1]);

    $this->getJson(FVR_API.'/ledger')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 2);
    $this->getJson(FVR_API.'/ledger?country=FR')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.document_number', 'FR-1');
    $this->getJson(FVR_API.'/ledger?category=6')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.document_number', 'DE-1');
    $this->getJson(FVR_API.'/ledger?search=fr-1')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson(FVR_API.'/ledger?status=REFUNDED')->assertOk()->assertJsonCount(0, 'data');

    $foreign = app(TenantContext::class)->runWithTenant($this->otherTenant, fn (): ForeignVatLedgerEntry => ForeignVatLedgerEntry::query()->create([
        'document_number' => 'OTHER', 'document_date' => '2026-01-10', 'supplier_country_code' => 'DE', 'currency_code' => 'EUR',
        'source_type' => 'MANUAL', 'fiscal_year' => 2026, 'fiscal_quarter' => 1,
    ]));

    $this->getJson(FVR_API.'/ledger/'.$foreign->id)->assertNotFound();
    $this->patchJson(FVR_API.'/ledger/'.$foreign->id, ['expense_category_code' => 1])->assertNotFound();
});

test('a manual line that is still pending can be deleted, a booked one cannot', function (): void {
    fvSetUp($this);
    $unposted = fvManual($this, ['post_to_ledger' => false])->assertCreated();
    $posted = fvEntry($this);

    $this->deleteJson(FVR_API.'/ledger/'.$posted->id)->assertUnprocessable()->assertJsonPath('code', 'ledger_entry_locked');
    $this->deleteJson(FVR_API.'/ledger/'.$unposted->json('data.id'))->assertOk();

    expect(ForeignVatLedgerEntry::query()->count())->toBe(1);
});

test('a supplier invoice with a foreign VAT feeds the ledger line by line and is not recorded twice', function (): void {
    fvSetUp($this);
    $supplier = Contact::create(['company_name' => 'Hotel Berlin GmbH', 'type' => 'supplier', 'country' => 'DE', 'vat_number' => 'DE123456789']);
    $invoice = PassiveInvoice::create(['supplier_id' => $supplier->id, 'invoice_number' => 'B-100', 'date' => '2026-05-02', 'total_amount' => '1190', 'total_net' => '1000', 'total_tax' => '190', 'currency' => 'EUR', 'document_type' => 'TD01', 'payment_status' => 'unpaid']);
    PassiveInvoiceLine::create(['tenant_id' => $this->tenant->id, 'passive_invoice_id' => $invoice->id, 'line_number' => 1, 'description' => 'Hotel accommodation, 2 nights', 'quantity' => 1, 'unit_price' => 600, 'line_total' => '600', 'tax_rate' => '19']);
    PassiveInvoiceLine::create(['tenant_id' => $this->tenant->id, 'passive_invoice_id' => $invoice->id, 'line_number' => 2, 'description' => 'Fuel for the rental car', 'quantity' => 1, 'unit_price' => 400, 'line_total' => '400', 'tax_rate' => '19']);

    event(new PassiveInvoicePostedEvent((string) $invoice->id, (string) $this->tenant->id));
    event(new PassiveInvoicePostedEvent((string) $invoice->id, (string) $this->tenant->id));

    $entries = ForeignVatLedgerEntry::query()->where('document_number', 'B-100')->orderBy('expense_category_code')->get();

    expect($entries)->toHaveCount(2)
        ->and($entries->pluck('expense_category_code')->all())->toBe([1, 6])
        ->and($entries->every(fn (ForeignVatLedgerEntry $entry): bool => $entry->source_type->value === 'PASSIVE_INVOICE' && $entry->supplier_country_code === 'DE' && $entry->journal_entry_id !== null))->toBeTrue()
        ->and(round((float) $entries->sum('recoverable_vat_amount'), 2))->toBe(190.0);
});

test('a domestic supplier invoice and a supplier credit note leave the ledger untouched', function (): void {
    fvSetUp($this);
    $domestic = Contact::create(['company_name' => 'Fornitore Srl', 'type' => 'supplier', 'country' => 'IT', 'vat_number' => 'IT09876543210']);
    $foreign = Contact::create(['company_name' => 'Hotel Berlin GmbH', 'type' => 'supplier', 'country' => 'DE', 'vat_number' => 'DE123456789']);
    $invoice = PassiveInvoice::create(['supplier_id' => $domestic->id, 'invoice_number' => 'D-1', 'date' => '2026-05-02', 'total_amount' => '122', 'total_net' => '100', 'total_tax' => '22', 'currency' => 'EUR', 'document_type' => 'TD01', 'payment_status' => 'unpaid']);
    $creditNote = PassiveInvoice::create(['supplier_id' => $foreign->id, 'invoice_number' => 'NC-1', 'date' => '2026-05-03', 'total_amount' => '119', 'total_net' => '100', 'total_tax' => '19', 'currency' => 'EUR', 'document_type' => 'TD04', 'payment_status' => 'unpaid']);

    event(new PassiveInvoicePostedEvent((string) $invoice->id, (string) $this->tenant->id));
    event(new PassiveInvoicePostedEvent((string) $creditNote->id, (string) $this->tenant->id));

    expect(ForeignVatLedgerEntry::query()->count())->toBe(0);
});

test('the interception can be switched off in the settings', function (): void {
    fvSetUp($this);
    $this->putJson(FVR_API.'/settings', ['auto_intercept' => false])->assertOk()->assertJsonPath('data.auto_intercept', false);
    $supplier = Contact::create(['company_name' => 'Hotel Berlin GmbH', 'type' => 'supplier', 'country' => 'DE', 'vat_number' => 'DE123456789']);
    $invoice = PassiveInvoice::create(['supplier_id' => $supplier->id, 'invoice_number' => 'B-200', 'date' => '2026-05-02', 'total_amount' => '119', 'total_net' => '100', 'total_tax' => '19', 'currency' => 'EUR', 'document_type' => 'TD01', 'payment_status' => 'unpaid']);

    event(new PassiveInvoicePostedEvent((string) $invoice->id, (string) $this->tenant->id));

    expect(ForeignVatLedgerEntry::query()->count())->toBe(0);
});

test('an approved expense report records its foreign VAT lines and the payout books them', function (): void {
    $this->travelTo('2027-07-10 10:00:00');
    (new ForeignVatRefundReferenceSeeder)->run();
    exmSetUp($this);
    fvLedgerSetUp($this);
    exmActAs($this, 'accountant');

    $reportId = exmCreateReport($this);
    exmAddLine($this, $reportId, 'meals', ['merchant_name' => 'Restaurant Berlin', 'merchant_tax_id' => 'DE123456789', 'original_amount' => '119.00', 'vat_rate' => '19', 'vat_amount' => '19.00'])->assertCreated();
    exmAddLine($this, $reportId, 'meals', ['merchant_name' => 'Trattoria Roma', 'original_amount' => '44.00', 'vat_rate' => '10', 'vat_amount' => '4.00'])->assertCreated();

    app(InterceptForeignVat::class)->onExpenseReportApproved(new ExpenseReportApprovedEvent($reportId, 'ER-1', 'user-1', '163.00', (string) $this->tenant->id));
    $entries = ForeignVatLedgerEntry::query()->get();

    expect($entries)->toHaveCount(1)
        ->and($entries->first()->source_type->value)->toBe('EXPENSE_REPORT')
        ->and($entries->first()->supplier_country_code)->toBe('DE')
        ->and($entries->first()->journal_entry_id)->toBeNull()
        ->and($entries->first()->status->value)->not->toBe('ELIGIBLE_ACCUMULATING');

    app(InterceptForeignVat::class)->onExpenseReportPosted(new ExpenseReportPostedEvent($reportId, 'ER-1', 'EUR', '2027-07-10', [], (string) $this->tenant->id, null));
    $entry = ForeignVatLedgerEntry::query()->firstOrFail();

    expect(ForeignVatLedgerEntry::query()->count())->toBe(1)
        ->and($entry->journal_entry_id)->not->toBeNull()
        ->and($entry->status->value)->toBe('ELIGIBLE_ACCUMULATING')
        ->and((string) $entry->recoverable_vat_amount)->toBe('19.00');
});
