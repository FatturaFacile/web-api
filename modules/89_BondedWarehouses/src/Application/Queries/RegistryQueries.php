<?php

declare(strict_types=1);

namespace Modules\BondedWarehouses\Application\Queries;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Modules\BondedWarehouses\Models\AeoComplianceAudit;
use Modules\BondedWarehouses\Models\BondedMrnLot;
use Modules\BondedWarehouses\Models\CguLedgerEntry;
use Modules\BondedWarehouses\Models\CustomsWarehouseAuthorization;
use Modules\BondedWarehouses\Models\VatDepotRegisterEntry;

/**
 * Paginated and filterable read models of the module (the query side: nothing here writes). Every query is tenant scoped by the models.
 * Matching is dialect-agnostic: LOWER(column) LIKE, never ILIKE.
 *
 * @phpstan-type Filters array<string, mixed>
 */
final class RegistryQueries
{
    /**
     * @param  Filters  $filters  regime_type, aeo_status, is_active, search
     * @return LengthAwarePaginator<int, CustomsWarehouseAuthorization>
     */
    public function authorizations(array $filters, int $perPage): LengthAwarePaginator
    {
        return CustomsWarehouseAuthorization::query()
            ->when($filters['regime_type'] ?? null, static fn (Builder $q, string $v) => $q->where('regime_type', $v))
            ->when($filters['aeo_status'] ?? null, static fn (Builder $q, string $v) => $q->where('aeo_status', $v))
            ->when(isset($filters['is_active']) && $filters['is_active'] !== '', static fn (Builder $q) => $q->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN)))
            ->when($filters['search'] ?? null, fn (Builder $q, string $v) => $this->like($q, ['authorization_code', 'customs_office_code', 'cgu_guarantee_reference', 'aeo_certificate_number'], $v))
            ->orderBy('authorization_code')
            ->paginate($perPage);
    }

    /**
     * @param  Filters  $filters  mrn, taric, regime_type, origin, status, authorization_id, product_id, declaration_type, has_balance
     * @return LengthAwarePaginator<int, BondedMrnLot>
     */
    public function mrnLots(array $filters, int $perPage): LengthAwarePaginator
    {
        return BondedMrnLot::query()
            ->with('authorization:id,authorization_code,regime_type,currency')
            ->when($filters['mrn'] ?? null, fn (Builder $q, string $v) => $this->like($q, ['mrn_number'], $v))
            ->when($filters['taric'] ?? null, static fn (Builder $q, string $v) => $q->where('taric_hs_code', 'like', preg_replace('/\D/', '', $v).'%'))
            ->when($filters['origin'] ?? null, static fn (Builder $q, string $v) => $q->where('country_of_origin', strtoupper($v)))
            ->when($filters['status'] ?? null, static fn (Builder $q, string $v) => $q->where('customs_status', $v))
            ->when($filters['authorization_id'] ?? null, static fn (Builder $q, string $v) => $q->where('authorization_id', $v))
            ->when($filters['product_id'] ?? null, static fn (Builder $q, string $v) => $q->where('product_id', $v))
            ->when($filters['declaration_type'] ?? null, static fn (Builder $q, string $v) => $q->where('declaration_type', $v))
            ->when($filters['regime_type'] ?? null, static fn (Builder $q, string $v) => $q->whereHas('authorization', static fn (Builder $a) => $a->where('regime_type', $v)))
            ->when(filter_var($filters['has_balance'] ?? false, FILTER_VALIDATE_BOOLEAN), static fn (Builder $q) => $q->where('remaining_quantity', '>', 0))
            ->orderByDesc('entry_date')
            ->orderBy('mrn_number')
            ->paginate($perPage);
    }

    public function mrnLot(string $id): BondedMrnLot
    {
        return BondedMrnLot::query()->with(['authorization:id,authorization_code,regime_type,currency', 'discharges'])->findOrFail($id);
    }

    /**
     * @param  Filters  $filters  authorization_id, direction, date_from, date_to
     * @return LengthAwarePaginator<int, CguLedgerEntry>
     */
    public function cguLedger(array $filters, int $perPage): LengthAwarePaginator
    {
        return CguLedgerEntry::query()
            ->when($filters['authorization_id'] ?? null, static fn (Builder $q, string $v) => $q->where('authorization_id', $v))
            ->when($filters['direction'] ?? null, static fn (Builder $q, string $v) => $q->where('movement_direction', $v))
            ->when($filters['date_from'] ?? null, static fn (Builder $q, string $v) => $q->where('recorded_at', '>=', $v.' 00:00:00'))
            ->when($filters['date_to'] ?? null, static fn (Builder $q, string $v) => $q->where('recorded_at', '<=', $v.' 23:59:59'))
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * @param  Filters  $filters  authorization_id, movement_type, product_id, date_from, date_to
     * @return LengthAwarePaginator<int, VatDepotRegisterEntry>
     */
    public function vatRegister(array $filters, int $perPage): LengthAwarePaginator
    {
        return VatDepotRegisterEntry::query()
            ->when($filters['authorization_id'] ?? null, static fn (Builder $q, string $v) => $q->where('authorization_id', $v))
            ->when($filters['movement_type'] ?? null, static fn (Builder $q, string $v) => $q->where('movement_type', $v))
            ->when($filters['product_id'] ?? null, static fn (Builder $q, string $v) => $q->where('product_id', $v))
            ->when($filters['date_from'] ?? null, static fn (Builder $q, string $v) => $q->whereDate('movement_date', '>=', $v))
            ->when($filters['date_to'] ?? null, static fn (Builder $q, string $v) => $q->whereDate('movement_date', '<=', $v))
            ->orderBy('authorization_id')
            ->orderBy('register_progressive_number')
            ->paginate($perPage);
    }

    /**
     * @param  Filters  $filters  authorization_id, audit_type, discrepancy_status, date_from, date_to
     * @return LengthAwarePaginator<int, AeoComplianceAudit>
     */
    public function aeoAudits(array $filters, int $perPage): LengthAwarePaginator
    {
        return AeoComplianceAudit::query()
            ->when($filters['authorization_id'] ?? null, static fn (Builder $q, string $v) => $q->where('authorization_id', $v))
            ->when($filters['audit_type'] ?? null, static fn (Builder $q, string $v) => $q->where('audit_type', $v))
            ->when($filters['discrepancy_status'] ?? null, static fn (Builder $q, string $v) => $q->where('discrepancy_status', $v))
            ->when($filters['date_from'] ?? null, static fn (Builder $q, string $v) => $q->whereDate('audit_date', '>=', $v))
            ->when($filters['date_to'] ?? null, static fn (Builder $q, string $v) => $q->whereDate('audit_date', '<=', $v))
            ->orderByDesc('audit_date')
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /**
     * @param  Builder<*>  $query
     * @param  list<string>  $columns
     * @return Builder<*>
     */
    private function like(Builder $query, array $columns, string $needle): Builder
    {
        $pattern = '%'.strtolower(addcslashes($needle, '%_\\')).'%';

        return $query->where(static function (Builder $q) use ($columns, $pattern): void {
            foreach ($columns as $column) {
                $q->orWhereRaw('LOWER('.$column.') LIKE ?', [$pattern]);
            }
        });
    }
}
