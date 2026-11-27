<?php

declare(strict_types=1);

namespace Modules\DownPayments\Services;

use Enterprise\MultiTenant\Scopes\TenantScope;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\DownPayments\Http\Resources\ChainDetailResource;
use Modules\DownPayments\Models\DownPaymentChain;
use Modules\Invoicing\Models\SalesInvoice;

/**
 * Reads of the chains: the filtered list and the full graph of one chain with its analytical matrix and journal entries.
 */
final class ChainReadService
{
    public function __construct(private readonly ChainLedger $ledger) {}

    /**
     * @param  array<string, mixed>  $filters  customer_id, status, source_type, source_id, country, search
     * @return LengthAwarePaginator<int, DownPaymentChain>
     */
    public function paginate(string $tenantId, array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $search = isset($filters['search']) ? mb_strtolower((string) $filters['search']) : null;

        return DownPaymentChain::query()
            ->where('tenant_id', $tenantId)
            ->when($filters['customer_id'] ?? null, static fn ($q, string $v) => $q->where('customer_id', $v))
            ->when($filters['status'] ?? null, static fn ($q, string $v) => $q->where('status', $v))
            ->when($filters['source_type'] ?? null, static fn ($q, string $v) => $q->where('source_type', $v))
            ->when($filters['source_id'] ?? null, static fn ($q, string $v) => $q->where('source_id', $v))
            ->when($filters['country'] ?? null, static fn ($q, string $v) => $q->where('jurisdiction', $v))
            ->when($search, static fn ($q, string $v) => $q->where(static fn ($w) => $w->whereRaw('LOWER(chain_number) LIKE ?', ['%'.$v.'%'])->orWhereRaw('LOWER(source_number) LIKE ?', ['%'.$v.'%'])->orWhereRaw('LOWER(customer_name) LIKE ?', ['%'.$v.'%'])))
            ->latest('created_at')
            ->latest('id')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    public function detail(DownPaymentChain $chain): ChainDetailResource
    {
        $chain->load(['installments', 'links.vatLines', 'deposits', 'deductionLines']);

        return new ChainDetailResource($chain, $this->ledger->matrix($chain), $this->journalEntries($chain));
    }

    /**
     * The journal entries posted by the documents and deposits of the chain.
     *
     * @return list<array{source: string, document_id: string, document_number: string|null, journal_entry_id: string}>
     */
    private function journalEntries(DownPaymentChain $chain): array
    {
        $entries = [];
        $documentIds = $chain->links->pluck('document_id')->filter()->map(static fn (mixed $id): string => (string) $id)->all();
        $invoices = $documentIds === [] ? collect() : SalesInvoice::withoutGlobalScope(TenantScope::class)->where('tenant_id', $chain->tenant_id)->whereIn('id', $documentIds)->whereNotNull('gl_journal_entry_id')->get(['id', 'invoice_number', 'gl_journal_entry_id']);

        foreach ($invoices as $invoice) {
            $entries[] = ['source' => 'invoice', 'document_id' => (string) $invoice->id, 'document_number' => (string) $invoice->invoice_number, 'journal_entry_id' => (string) $invoice->gl_journal_entry_id];
        }

        foreach ($chain->deposits as $deposit) {
            foreach (['journal_entry_id' => 'deposit_receipt', 'resolution_journal_entry_id' => 'deposit_resolution'] as $column => $source) {
                if ($deposit->{$column} !== null) {
                    $entries[] = ['source' => $source, 'document_id' => (string) $deposit->id, 'document_number' => (string) $deposit->receipt_number, 'journal_entry_id' => (string) $deposit->{$column}];
                }
            }
        }

        return $entries;
    }
}
