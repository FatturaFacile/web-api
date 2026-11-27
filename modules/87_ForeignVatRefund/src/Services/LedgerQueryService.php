<?php

declare(strict_types=1);

namespace Modules\ForeignVatRefund\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\ForeignVatRefund\Models\ForeignVatLedgerEntry;

/**
 * Filtered, paged read of the foreign VAT ledger of the active company.
 */
final class LedgerQueryService
{
    /**
     * @param  array<string, mixed>  $filters  country, status, category, fiscal_year, fiscal_quarter, source_type, claim_id, search
     * @return LengthAwarePaginator<int, ForeignVatLedgerEntry>
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $search = isset($filters['search']) ? mb_strtolower(trim((string) $filters['search'])) : '';

        return ForeignVatLedgerEntry::query()
            ->with('claim:id,claim_number')
            ->when(! empty($filters['country']), fn ($query) => $query->where('supplier_country_code', strtoupper((string) $filters['country'])))
            ->when(! empty($filters['status']), fn ($query) => $query->where('status', (string) $filters['status']))
            ->when(! empty($filters['category']), fn ($query) => $query->where('expense_category_code', (int) $filters['category']))
            ->when(! empty($filters['fiscal_year']), fn ($query) => $query->where('fiscal_year', (int) $filters['fiscal_year']))
            ->when(! empty($filters['fiscal_quarter']), fn ($query) => $query->where('fiscal_quarter', (int) $filters['fiscal_quarter']))
            ->when(! empty($filters['source_type']), fn ($query) => $query->where('source_type', (string) $filters['source_type']))
            ->when(! empty($filters['claim_id']), fn ($query) => $query->where('vat_refund_claim_id', (string) $filters['claim_id']))
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->whereRaw('LOWER(document_number) LIKE ?', ['%'.$search.'%'])
                ->orWhereRaw('LOWER(supplier_name) LIKE ?', ['%'.$search.'%'])))
            ->orderByDesc('document_date')
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }
}
