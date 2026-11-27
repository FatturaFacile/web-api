<?php

declare(strict_types=1);

use App\Models\Invoice;
use App\Models\Tenant;
use App\Services\ItalianSdi\FatturaPaBuilderService;
use Illuminate\Testing\TestResponse;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\JournalLine;
use Modules\Invoicing\Models\SalesInvoice;

require_once __DIR__.'/../Invoicing/InvoicingBillingSupport.php';

/**
 * Two Italian companies, a customer each and an accountant signed in, plus a confirmed sales order of the first one:
 * 10 x 100.00 at 22% and 5 x 40.00 at 10% (net 1200.00, VAT 240.00, gross 1440.00).
 */
function dpSetUp(object $test): void
{
    billingSetUp($test);
    $test->order = billingOrder($test->tenant, $test->customer);
}

/**
 * Opens a chain from the order of the test (or from the given payload overrides) and returns the chain as the API states it.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function dpOpenChain(object $test, array $overrides = [], ?string $orderId = null): array
{
    return $test->postJson('/api/v1/down-payment-chains/from-source', array_merge([
        'source_type' => 'sales_order',
        'source_id' => $orderId ?? (string) $test->order->id,
        'template' => '30_40_30',
    ], $overrides))->assertCreated()->json('data');
}

/**
 * @param  array<string, mixed>  $payload
 */
function dpEmit(object $test, string $chainId, string $installmentId, array $payload = []): TestResponse
{
    return $test->postJson("/api/v1/down-payment-chains/{$chainId}/installments/{$installmentId}/emit", $payload);
}

/**
 * @return array<string, mixed>
 */
function dpChain(object $test, string $chainId): array
{
    return $test->getJson("/api/v1/down-payment-chains/{$chainId}")->assertOk()->json('data');
}

/**
 * The balance (debit less credit) of an account of a company, by its code.
 */
function dpBalance(Tenant $tenant, string $code): string
{
    $account = ChartOfAccount::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('account_code', $code)->first();

    if ($account === null) {
        return '0.00';
    }

    $debit = (string) JournalLine::withoutGlobalScopes()->where('account_id', $account->id)->sum('debit_amount');
    $credit = (string) JournalLine::withoutGlobalScopes()->where('account_id', $account->id)->sum('credit_amount');

    return bcsub($debit, $credit, 2);
}

function dpInvoice(string $id): SalesInvoice
{
    return SalesInvoice::withoutGlobalScopes()->with('items')->findOrFail($id);
}

function dpXml(string $invoiceId): string
{
    return app(FatturaPaBuilderService::class)->buildFatturaPaXml(Invoice::withoutGlobalScopes()->with(['tenant', 'contact.addresses', 'items'])->findOrFail($invoiceId));
}

/**
 * The installment of a chain by sequence.
 *
 * @param  array<string, mixed>  $chain
 * @return array<string, mixed>
 */
function dpInstallment(array $chain, int $sequence): array
{
    return collect($chain['installments'])->firstWhere('sequence', $sequence);
}
