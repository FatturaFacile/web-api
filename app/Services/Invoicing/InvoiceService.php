<?php

declare(strict_types=1);

namespace App\Services\Invoicing;

use App\Models\Invoice;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Invoicing\Contracts\InvoicingServiceInterface;

/**
 * Entry point of the sales API of the app for creating sales documents. It owns no arithmetic, numbering or posting:
 * it maps the payload of the app to the invoicing service of the invoicing module, the single writer of the `invoices`
 * table, and returns the document as the app model. In this flow the creation is the emission: anything but a proforma
 * is numbered and posted to the ledger in the same transaction, so a closed period leaves neither the document nor a gap.
 */
class InvoiceService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly InvoicingServiceInterface $invoicing,
    ) {}

    /**
     * Creates (and, unless it is a proforma, issues) a document from the validated payload of the sales API.
     *
     * @param  array<string, mixed>  $data
     */
    public function createInvoice(array $data): Invoice
    {
        $items = array_map(static fn (array $item): array => array_intersect_key($item, array_flip([
            'product_id', 'description', 'quantity', 'unit_price', 'tax_rate', 'discount_percentage', 'revenue_account_id', 'product_charge_type_id',
        ])), $data['items'] ?? []);
        unset($data['items'], $data['invoice_number']);

        return $this->issue($data, $items);
    }

    /**
     * Creates a document from a header and lines built by another flow of the app (quotes, proformas, work and service
     * reports) and issues it unless it is a proforma. The header may carry links to its source (quote_id, proforma_id,
     * sales_order_id...); the lines are priced by the caller.
     *
     * @param  array<string, mixed>  $header
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function issue(array $header, array $lines): Invoice
    {
        return DB::transaction(function () use ($header, $lines): Invoice {
            $header['tenant_id'] = (string) ($header['tenant_id'] ?? $this->tenantContext->getTenantId());
            $header['currency'] = strtoupper((string) ($header['currency'] ?? 'EUR'));
            $header['created_by'] ??= Auth::id();

            // The app documents state no payment details unless the caller does: keep them empty rather than defaulted.
            foreach (['due_date', 'payment_method', 'payment_terms'] as $field) {
                $header[$field] ??= null;
            }

            $userId = (string) (Auth::id() ?? 'system');
            $created = ($header['invoice_type'] ?? 'standard') === 'proforma'
                ? $this->invoicing->createInvoice($header, $lines)
                : $this->invoicing->issueInvoice($header, $lines, $userId);

            return Invoice::query()->with(['items', 'contact'])->findOrFail($created->id);
        });
    }
}
