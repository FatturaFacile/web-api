<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\PassiveInvoice;
use App\Models\Tenant;
use Illuminate\Testing\TestResponse;
use Modules\AccountsPayable\Models\ApOpenItem;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\JournalLine;

require_once __DIR__.'/../Treasury/TreasurySupport.php';

/**
 * Builders of the accounts payable tests (Mod 015). They reuse the treasury support: two companies with an open fiscal
 * calendar, an accountant signed in on the first one, and bank accounts and supplier invoices.
 */
function apSetUp(object $test): void
{
    treasurySetUp($test);
    $test->supplier = apSupplier($test->tenant, 'Fornitore Alfa SRL', 'IT60X0542811101000000123456');
}

/**
 * @param  array<string, mixed>  $extra
 */
function apSupplier(Tenant $tenant, string $name, ?string $iban = 'IT60X0542811101000000123456', array $extra = []): Contact
{
    return treasuryInTenant($tenant, fn (): Contact => Contact::create(array_merge([
        'tenant_id' => $tenant->id, 'company_name' => $name, 'email' => strtolower(str_replace(' ', '', $name)).'@example.com', 'type' => 'supplier', 'country' => 'IT', 'iban' => $iban,
    ], $extra)));
}

/**
 * A received supplier invoice that falls due `$dueInDays` days from today (negative when already overdue).
 *
 * @param  array<string, mixed>  $overrides
 */
function apInvoice(object $test, Contact $supplier, string $number, string $amount, int $dueInDays = 5, array $overrides = []): PassiveInvoice
{
    return treasurySupplierInvoice($test->tenant, $supplier, $number, $amount, array_merge(['due_date' => now()->addDays($dueInDays)->toDateString()], $overrides));
}

function apCreditNote(object $test, Contact $supplier, string $number, string $amount, int $dueInDays = 0): PassiveInvoice
{
    return apInvoice($test, $supplier, $number, $amount, $dueInDays, ['document_type' => 'TD04']);
}

/**
 * The open item of a received document, created by the sync of the list.
 */
function apItem(PassiveInvoice $invoice): ApOpenItem
{
    return ApOpenItem::withoutGlobalScopes()->where('passive_invoice_id', $invoice->id)->firstOrFail();
}

/**
 * Lists the open items, which brings the ledger in line with the invoices.
 */
function apSync(object $test): TestResponse
{
    return $test->getJson('/api/v1/accounts-payable/open-items?per_page=200')->assertOk();
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function apRunPayload(array $overrides = []): array
{
    return array_merge([
        'currency' => 'EUR',
        'cutoff_date' => now()->addDays(10)->toDateString(),
        'execution_date' => now()->toDateString(),
    ], $overrides);
}

/**
 * The first problem code of an error response.
 */
function apErrorCode(TestResponse $response): ?string
{
    return $response->json('invalid_params.0.code');
}

/**
 * The balance (debit less credit) of a ledger account of a company.
 */
function apAccountBalance(string $tenantId, string $code): string
{
    $accountId = ChartOfAccount::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('account_code', $code)->value('id');

    if ($accountId === null) {
        return '0.00';
    }

    $sum = JournalLine::withoutGlobalScopes()->where('account_id', $accountId)->selectRaw('COALESCE(SUM(debit_amount - credit_amount), 0) as balance')->value('balance');

    return number_format((float) $sum, 2, '.', '');
}
