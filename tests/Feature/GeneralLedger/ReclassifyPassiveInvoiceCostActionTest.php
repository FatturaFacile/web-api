<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\PassiveInvoice;
use App\Models\PassiveInvoiceLine;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Actions\OpenFiscalYearAction;
use Modules\GeneralLedger\Actions\PostPassiveInvoiceAction;
use Modules\GeneralLedger\Actions\ReclassifyPassiveInvoiceCostAction;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Models\JournalLine;

uses(RefreshDatabase::class);

/**
 * Manual override of the cost account of a received document: before the posting only the document changes, after it the
 * original entry stays and a reclassification entry (dated today) moves the net amount from the old account to the new
 * one; a credit note moves it the other way round. Amounts are net of VAT, as the cost allocation of the document.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Reclass '.Str::random(4), 'legal_name' => 'Reclass SA', 'country' => 'CH', 'default_currency' => 'CHF']);
    app(TenantContext::class)->setTenant($this->tenant);
    app(OpenFiscalYearAction::class)->execute((string) $this->tenant->id, (int) now()->format('Y'));
    $this->supplier = Contact::create(['tenant_id' => $this->tenant->id, 'type' => 'supplier', 'company_name' => 'Fornitore SA', 'country' => 'CH']);
    $this->target = reclassAccount($this->tenant, '6300');
    $this->other = reclassAccount($this->tenant, '6100');
});

function reclassAccount(Tenant $tenant, string $code): ChartOfAccount
{
    return ChartOfAccount::create([
        'tenant_id' => (string) $tenant->id, 'account_code' => $code, 'name' => "Cost {$code}", 'account_class' => 'expense', 'account_type' => 'expense',
        'normal_balance' => 'debit', 'is_active' => true,
    ]);
}

/**
 * A received document of CHF 150.00 net in two lines (100.00 and 50.00) with 8.1% VAT, posted or not.
 */
function reclassInvoice(bool $post, string $type = 'TD01'): PassiveInvoice
{
    $tenant = test()->tenant;
    $invoice = PassiveInvoice::create([
        'tenant_id' => $tenant->id,
        'supplier_id' => test()->supplier->id,
        'invoice_number' => 'SUP-'.Str::random(4),
        'document_type' => $type,
        'date' => now()->toDateString(),
        'total_net' => '150.00',
        'total_tax' => '12.15',
        'total_amount' => '162.15',
        'currency' => 'CHF',
        'payment_status' => 'unpaid',
    ]);

    foreach ([[1, '100.00'], [2, '50.00']] as [$number, $total]) {
        PassiveInvoiceLine::create(['tenant_id' => $tenant->id, 'passive_invoice_id' => $invoice->id, 'line_number' => $number, 'description' => "Line {$number}", 'quantity' => 1, 'unit_price' => $total, 'line_total' => $total, 'tax_rate' => '8.1']);
    }

    if ($post) {
        app(PostPassiveInvoiceAction::class)->execute($invoice);
    }

    return $invoice->refresh();
}

function reclassBalance(string $code): string
{
    $accountId = ChartOfAccount::withoutGlobalScopes()->where('tenant_id', test()->tenant->id)->where('account_code', $code)->value('id');

    return number_format((float) JournalLine::withoutGlobalScopes()->where('account_id', $accountId)->selectRaw('COALESCE(SUM(debit_amount - credit_amount), 0) as balance')->value('balance'), 2, '.', '');
}

function reclassEntries(): int
{
    return JournalEntry::withoutGlobalScopes()->where('tenant_id', test()->tenant->id)->where('source_document_type', 'vendor_invoice_reclassification')->count();
}

test('before the posting only the document and its lines change', function (): void {
    $invoice = reclassInvoice(false);

    $result = app(ReclassifyPassiveInvoiceCostAction::class)->execute($invoice, (string) $this->target->id);

    expect($result->cost_account_id)->toBe((string) $this->target->id)
        ->and($result->cost_account_source)->toBe('manual')
        ->and($result->lines()->pluck('cost_account_id')->unique()->all())->toBe([(string) $this->target->id])
        ->and($result->lines()->pluck('cost_account_source')->unique()->all())->toBe(['manual'])
        ->and(reclassEntries())->toBe(0);
});

test('after the posting the original entry stays and the net amount moves to the new account', function (): void {
    $invoice = reclassInvoice(true);

    expect(reclassBalance('6000'))->toBe('150.00')
        ->and(reclassBalance('6300'))->toBe('0.00');

    app(ReclassifyPassiveInvoiceCostAction::class)->execute($invoice, (string) $this->target->id);

    $entry = JournalEntry::withoutGlobalScopes()->where('source_document_type', 'vendor_invoice_reclassification')->sole();
    $lines = JournalLine::withoutGlobalScopes()->where('journal_entry_id', $entry->id)->get();

    expect(reclassBalance('6000'))->toBe('0.00')
        ->and(reclassBalance('6300'))->toBe('150.00')
        ->and($lines)->toHaveCount(2)
        ->and((float) $lines->sum('debit_amount'))->toEqual(150.0)
        ->and((float) $lines->sum('credit_amount'))->toEqual(150.0)
        ->and($entry->description)->toContain($invoice->invoice_number)
        ->and(JournalEntry::withoutGlobalScopes()->where('source_document_id', $invoice->id)->where('source_document_type', 'vendor_invoice')->count())->toBe(1);
});

test('only the lines asked for are moved and the document keeps its own account', function (): void {
    $invoice = reclassInvoice(true);
    $first = $invoice->lines()->where('line_number', 1)->sole();

    $result = app(ReclassifyPassiveInvoiceCostAction::class)->execute($invoice, (string) $this->target->id, [(string) $first->id]);

    expect(reclassBalance('6000'))->toBe('50.00')
        ->and(reclassBalance('6300'))->toBe('100.00')
        ->and($result->cost_account_id)->toBeNull()
        ->and($first->fresh()->cost_account_id)->toBe((string) $this->target->id)
        ->and($invoice->lines()->where('line_number', 2)->value('cost_account_id'))->toBeNull();
});

test('lines on different accounts leave each one of them for the new account', function (): void {
    $invoice = reclassInvoice(false);
    $invoice->lines()->where('line_number', 2)->update(['cost_account_id' => (string) $this->other->id]);
    app(PostPassiveInvoiceAction::class)->execute($invoice->refresh());

    $before6100 = reclassBalance('6100');
    app(ReclassifyPassiveInvoiceCostAction::class)->execute($invoice, (string) $this->target->id);

    expect($before6100)->toBe('50.00')
        ->and(reclassBalance('6100'))->toBe('0.00')
        ->and(reclassBalance('6000'))->toBe('0.00')
        ->and(reclassBalance('6300'))->toBe('150.00')
        ->and(reclassEntries())->toBe(1);
});

test('moving to the account a line already has posts nothing', function (): void {
    $invoice = reclassInvoice(true);
    $default = ChartOfAccount::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->where('account_code', '6000')->value('id');

    app(ReclassifyPassiveInvoiceCostAction::class)->execute($invoice, (string) $default);

    expect(reclassEntries())->toBe(0)
        ->and(reclassBalance('6000'))->toBe('150.00');
});

test('a supplier credit note moves the amount the other way round', function (): void {
    $invoice = reclassInvoice(true, 'TD04');

    expect(reclassBalance('6000'))->toBe('-150.00');

    app(ReclassifyPassiveInvoiceCostAction::class)->execute($invoice, (string) $this->target->id);

    expect(reclassBalance('6000'))->toBe('0.00')
        ->and(reclassBalance('6300'))->toBe('-150.00');
});

test('a document of another tenant cannot be reclassified', function (): void {
    $invoice = reclassInvoice(false);
    $other = Tenant::create(['name' => 'Other', 'legal_name' => 'Other SA', 'country' => 'CH', 'default_currency' => 'CHF']);
    app(TenantContext::class)->setTenant($other);

    expect(fn () => app(ReclassifyPassiveInvoiceCostAction::class)->execute($invoice, (string) $this->target->id))
        ->toThrow(ModelNotFoundException::class);
});
