<?php

declare(strict_types=1);

namespace App\Services\Invoicing;

use App\Models\Invoice;
use App\Models\Product;
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
            'taric_code', 'country_of_origin', 'origin_type', 'made_in_label',
        ])), $this->withCustomsSnapshot($data['items'] ?? []));
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

    /**
     * Copies the customs data of the product onto every line that does not state its own: the document keeps its own
     * snapshot, so later product edits never rewrite an issued document.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function withCustomsSnapshot(array $items): array
    {
        $ids = array_values(array_unique(array_filter(array_column($items, 'product_id'))));
        $products = $ids === [] ? [] : Product::query()->whereIn('id', $ids)->get()->keyBy('id')->all();

        return array_map(static function (array $item) use ($products): array {
            $product = isset($item['product_id']) ? ($products[$item['product_id']] ?? null) : null;

            return [
                ...$item,
                'taric_code' => $item['taric_code'] ?? $product?->taric_code,
                'country_of_origin' => $item['country_of_origin'] ?? $product?->country_of_origin,
                'origin_type' => $item['origin_type'] ?? $product?->origin_type?->value,
                'made_in_label' => $item['made_in_label'] ?? $product?->made_in_label,
            ];
        }, $items);
    }
}
