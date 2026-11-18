<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Services;

use Carbon\CarbonImmutable;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Services\EventDispatcher;
use Enterprise\MultiTenant\Scopes\TenantScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\StockSerialNumber;
use Modules\ServiceManagement\DTOs\IssueLoanerDTO;
use Modules\ServiceManagement\Enums\DdtCausale;
use Modules\ServiceManagement\Enums\LoanerRegisterType;
use Modules\ServiceManagement\Enums\LoanerStatus;
use Modules\ServiceManagement\Enums\RmaStatus;
use Modules\ServiceManagement\Events\LoanerIssuedEvent;
use Modules\ServiceManagement\Events\LoanerOverdueEvent;
use Modules\ServiceManagement\Exceptions\WarrantyRmaException;
use Modules\ServiceManagement\Models\DepotRepairRmaOrder;
use Modules\ServiceManagement\Models\Loaner;

final readonly class LoanerService
{
    public function __construct(
        private EventDispatcher $dispatcher,
        private AuditLedgerInterface $auditLedger,
    ) {}

    public function issue(DepotRepairRmaOrder $rma, IssueLoanerDTO $dto, ?string $actorUserId = null): Loaner
    {
        if ($rma->statusEnum()->isTerminal()) {
            throw WarrantyRmaException::invalidTransition($rma->status, 'loaner_issue');
        }

        $isLoanerUnit = $dto->registerType === LoanerRegisterType::PrestitoUso->value;
        $serialNumber = $dto->loanerSerialNumber;
        $productId = $dto->loanerProductId;

        if ($dto->loanerStockSerialNumberId !== null) {
            $stock = StockSerialNumber::query()->whereKey($dto->loanerStockSerialNumberId)->first();
            if ($stock === null) {
                throw WarrantyRmaException::validation('loaner_stock_serial_number_id', 'The selected stock serial number does not exist.', 'stock_serial_not_found');
            }
            $serialNumber ??= $stock->serial_number;
        }

        if ($serialNumber === null || trim($serialNumber) === '') {
            throw WarrantyRmaException::validation('loaner_serial_number', 'A loaner serial number is required.');
        }

        return DB::transaction(function () use ($rma, $dto, $isLoanerUnit, $serialNumber, $productId): Loaner {
            /** @var DepotRepairRmaOrder $rma */
            $rma = DepotRepairRmaOrder::query()->whereKey($rma->getKey())->lockForUpdate()->firstOrFail();

            if ($isLoanerUnit) {
                $active = $rma->loaners()
                    ->where('register_type', LoanerRegisterType::PrestitoUso->value)
                    ->where('status', LoanerStatus::Issued->value)
                    ->exists();

                if ($active) {
                    throw new WarrantyRmaException('A loaner unit is already issued for this RMA.', 'loaner_already_issued', 409);
                }
            }

            $loaner = Loaner::create([
                'tenant_id' => $rma->tenant_id,
                'company_id' => $rma->company_id,
                'rma_order_id' => $rma->id,
                'loaner_stock_serial_number_id' => $dto->loanerStockSerialNumberId,
                'loaner_serial_number' => trim($serialNumber),
                'loaner_product_id' => $productId,
                'customer_id' => $rma->customer_id,
                'register_type' => $dto->registerType,
                'issued_at' => $dto->issuedAt !== null ? CarbonImmutable::parse($dto->issuedAt) : now(),
                'expected_return_at' => $dto->expectedReturnAt !== null ? CarbonImmutable::parse($dto->expectedReturnAt) : null,
                'status' => LoanerStatus::Issued->value,
                'ddt_causale' => $dto->ddtCausale ?? ($isLoanerUnit ? DdtCausale::PrestitoUso->value : DdtCausale::ContoRiparazione->value),
                'notes' => $dto->notes,
            ]);

            if ($isLoanerUnit) {
                $rma->loaner_id = $loaner->id;
                $rma->save();
            }

            $this->auditLedger->record(
                'LOANER_ISSUED',
                $loaner,
                null,
                ['rma_order_id' => $rma->id, 'register_type' => $loaner->register_type, 'serial' => $loaner->loaner_serial_number],
                'MODULE_17_ESM'
            );

            $this->dispatcher->dispatch(new LoanerIssuedEvent(
                loanerId: $loaner->id,
                rmaOrderId: $rma->id,
                loanerSerialNumber: $loaner->loaner_serial_number,
                registerType: $loaner->register_type,
                customerId: $loaner->customer_id,
                tenantId: $loaner->tenant_id,
                companyId: (string) ($loaner->company_id ?? $loaner->tenant_id),
            ));

            return $loaner->fresh(['rmaOrder']) ?? $loaner;
        });
    }

    public function markReturned(Loaner $loaner, ?string $returnedAt = null): Loaner
    {
        return $this->close($loaner, LoanerStatus::Returned, $returnedAt !== null ? CarbonImmutable::parse($returnedAt) : CarbonImmutable::now());
    }

    public function markLost(Loaner $loaner): Loaner
    {
        return $this->close($loaner, LoanerStatus::Lost, null);
    }

    /**
     * Raise LoanerOverdueEvent once per "prestito d'uso" unit still out after the repaired equipment was returned
     * (RMA returned/closed, or cancelled while a loaner was out) for more than the grace period. Idempotent through `overdue_alerted_at`.
     * Runs across tenants (scheduler has no tenant context) unless $tenantId is given.
     *
     * @return int number of loaners alerted in this run
     */
    public function scanOverdue(?string $tenantId = null, ?int $graceDays = null): int
    {
        $graceDays ??= (int) config('service-management.rma.loaner_grace_days', 7);
        $now = now();
        $cutoff = $now->copy()->subDays($graceDays);

        $candidates = Loaner::withoutGlobalScope(TenantScope::class)
            ->select('esm_loaners.*')
            ->join('esm_rma_orders as rma', 'rma.id', '=', 'esm_loaners.rma_order_id')
            ->whereNull('rma.deleted_at')
            ->where('esm_loaners.register_type', LoanerRegisterType::PrestitoUso->value)
            ->where('esm_loaners.status', LoanerStatus::Issued->value)
            ->whereNull('esm_loaners.overdue_alerted_at')
            ->whereIn('rma.status', [RmaStatus::Returned->value, RmaStatus::Closed->value, RmaStatus::Cancelled->value])
            ->where(function ($query) use ($cutoff): void {
                $query->where('rma.returned_at', '<', $cutoff)
                    ->orWhere(function ($inner) use ($cutoff): void {
                        $inner->whereNull('rma.returned_at')->where('rma.updated_at', '<', $cutoff);
                    });
            })
            ->when($tenantId !== null, fn ($query) => $query->where('esm_loaners.tenant_id', $tenantId))
            ->orderBy('esm_loaners.issued_at')
            ->get();

        $alerted = 0;
        foreach ($candidates as $loaner) {
            $claimed = Loaner::withoutGlobalScope(TenantScope::class)
                ->whereKey($loaner->id)
                ->whereNull('overdue_alerted_at')
                ->update(['overdue_alerted_at' => $now, 'updated_at' => $now]);

            if ($claimed !== 1) {
                continue;
            }

            $this->dispatcher->dispatch(new LoanerOverdueEvent(
                loanerId: $loaner->id,
                rmaOrderId: $loaner->rma_order_id,
                loanerSerialNumber: $loaner->loaner_serial_number,
                customerId: $loaner->customer_id,
                daysOutstanding: $loaner->daysOutstandingAt($now),
                tenantId: $loaner->tenant_id,
                companyId: (string) ($loaner->company_id ?? $loaner->tenant_id),
            ));
            $alerted++;
        }

        return $alerted;
    }

    private function close(Loaner $loaner, LoanerStatus $target, ?CarbonImmutable $returnedAt): Loaner
    {
        return DB::transaction(function () use ($loaner, $target, $returnedAt): Loaner {
            /** @var Loaner $loaner */
            $loaner = Loaner::query()->whereKey($loaner->getKey())->lockForUpdate()->firstOrFail();

            if ($loaner->status !== LoanerStatus::Issued->value) {
                throw WarrantyRmaException::invalidTransition($loaner->status, $target->value);
            }

            $loaner->status = $target->value;
            $loaner->returned_at = $returnedAt !== null ? Carbon::instance($returnedAt) : null;
            $loaner->save();

            $this->auditLedger->record(
                'LOANER_'.strtoupper($target->value),
                $loaner,
                ['status' => LoanerStatus::Issued->value],
                ['status' => $target->value],
                'MODULE_17_ESM'
            );

            return $loaner->fresh(['rmaOrder']) ?? $loaner;
        });
    }
}
