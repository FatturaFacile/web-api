<?php

declare(strict_types=1);

namespace Modules\Contracts\Services;

use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Contracts\Enums\ContractKind;
use Modules\Contracts\Models\ContractRenewal;
use Modules\Procurement\Models\SupplierContract;
use Modules\ServiceManagement\Models\ServiceContract;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * One view over customer and supplier contracts, with their terms, renewal and notice deadline.
 */
final readonly class ContractRegister
{
    public function __construct(private TenantContext $tenantContext) {}

    /**
     * @param  array{kind?: ?string, status?: ?string, expiring_within_days?: int|string|null}  $filters
     * @return LengthAwarePaginator<int, object>
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $parts = [];

        if (($filters['kind'] ?? null) !== ContractKind::Supplier->value) {
            $parts[] = ServiceContract::query()->toBase()->selectRaw("'customer' as kind, id, contract_number, title, customer_id as counterparty_id, start_date, end_date, annual_value as contract_value, currency, status, auto_renew, notice_period_days, renewal_term_months, alert_days_before, notice_deadline, notice_given_at");
        }

        if (($filters['kind'] ?? null) !== ContractKind::Customer->value) {
            $parts[] = SupplierContract::query()->toBase()->selectRaw("'supplier' as kind, id, contract_number, title, supplier_id as counterparty_id, starts_at as start_date, ends_at as end_date, total_committed_amount as contract_value, null as currency, status, auto_renew, notice_period_days, renewal_term_months, alert_days_before, notice_deadline, notice_given_at");
        }

        $union = array_shift($parts);

        foreach ($parts as $part) {
            $union->unionAll($part);
        }

        return DB::query()->fromSub($union, 'contracts')
            ->when($filters['status'] ?? null, static fn (Builder $query, string $status): Builder => $query->where('status', $status))
            ->when($filters['expiring_within_days'] ?? null, static fn (Builder $query, int|string $days): Builder => $query->whereBetween('end_date', [now()->toDateString(), now()->addDays((int) $days)->toDateString()]))
            ->orderBy('end_date')
            ->paginate($perPage);
    }

    public function find(ContractKind $kind, string $id): Model
    {
        return $kind->modelClass()::query()->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(ContractKind $kind, array $data): Model
    {
        $terms = $this->termsOf($data);
        $tenantId = (string) $this->tenantContext->getTenantId();

        $attributes = $kind === ContractKind::Customer
            ? [
                'tenant_id' => $tenantId,
                'customer_id' => $data['counterparty_id'],
                'contract_number' => $data['contract_number'],
                'title' => $data['title'],
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'] ?? null,
                'annual_value' => $data['contract_value'] ?? '0',
                'currency' => $data['currency'] ?? 'EUR',
                'status' => $data['status'] ?? 'active',
            ]
            : [
                'tenant_id' => $tenantId,
                'supplier_id' => $data['counterparty_id'],
                'contract_number' => $data['contract_number'],
                'title' => $data['title'],
                'starts_at' => $data['start_date'],
                'ends_at' => $data['end_date'],
                'total_committed_amount' => $data['contract_value'] ?? '0',
                'status' => $data['status'] ?? 'active',
            ];

        $contract = $kind->modelClass()::create([...$attributes, ...$terms]);
        $this->refreshNoticeDeadline($kind, $contract);

        return $contract;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateTerms(ContractKind $kind, Model $contract, array $data): Model
    {
        $contract->update($this->termsOf($data));
        $this->refreshNoticeDeadline($kind, $contract);

        return $contract;
    }

    /**
     * Extends the contract by its renewal term or up to a chosen date, and records it.
     *
     * @throws ConflictHttpException when the contract has no end date, or no term and no date
     */
    public function renew(ContractKind $kind, Model $contract, ?string $newEndDate, string $mode, ?string $userId): Model
    {
        return DB::transaction(function () use ($kind, $contract, $newEndDate, $mode, $userId): Model {
            $column = $kind->endColumn();
            $locked = $kind->modelClass()::withoutGlobalScopes()->lockForUpdate()->findOrFail($contract->getKey());
            $currentEnd = $locked->getAttribute($column);

            if ($currentEnd === null) {
                throw new ConflictHttpException('This contract has no end date to extend.');
            }

            if ($newEndDate === null && empty($locked->renewal_term_months)) {
                throw new ConflictHttpException('This contract has no renewal term: state the new end date.');
            }

            $newEnd = $newEndDate ?? CarbonImmutable::parse($currentEnd)->addMonthsNoOverflow((int) $locked->renewal_term_months)->toDateString();

            ContractRenewal::create([
                'tenant_id' => $locked->tenant_id,
                'contract_kind' => $kind->value,
                'contract_id' => $locked->getKey(),
                'previous_end_date' => CarbonImmutable::parse($currentEnd)->toDateString(),
                'new_end_date' => $newEnd,
                'mode' => $mode,
                'renewed_by' => $userId,
            ]);

            $locked->update([$column => $newEnd, 'notice_given_at' => null]);
            $this->refreshNoticeDeadline($kind, $locked);

            return $locked;
        });
    }

    /**
     * Records that notice was given, which stops the automatic renewal.
     */
    public function giveNotice(Model $contract): Model
    {
        $contract->update(['notice_given_at' => now()]);

        return $contract;
    }

    /**
     * Last day to give notice: the end date minus the notice period.
     */
    public function refreshNoticeDeadline(ContractKind $kind, Model $contract): void
    {
        $end = $contract->getAttribute($kind->endColumn());
        $days = $contract->getAttribute('notice_period_days');

        $contract->update(['notice_deadline' => $end === null || $days === null ? null : CarbonImmutable::parse($end)->subDays((int) $days)->toDateString()]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function termsOf(array $data): array
    {
        return collect($data)->only(['auto_renew', 'notice_period_days', 'renewal_term_months', 'alert_days_before'])->all();
    }
}
