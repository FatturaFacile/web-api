<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Services;

use Illuminate\Support\Facades\DB;
use Modules\ServiceManagement\Events\ContractHoursDeducted;
use Modules\ServiceManagement\Exceptions\HourBankException;
use Modules\ServiceManagement\Models\ContractHourTransaction;
use Modules\ServiceManagement\Models\ServiceContract;

/**
 * Maintenance-contract hour bank (monte-ore a scalare). Every change goes through one locked transaction that
 * re-reads the contract, writes an immutable ledger row and updates the counters, so the balance can never drift
 * from the ledger nor become negative. Hours are decimal strings (2 decimals), never floats.
 */
final class HourBankService
{
    /**
     * Books worked hours against the bank. Idempotent per work order: a second call for the same work order
     * returns the existing ledger row.
     *
     * @param  array{work_order_id?: string|null, ticket_id?: string|null, equipment_passport_id?: string|null, technician_name?: string|null, reference?: string|null, description?: string|null}  $context
     */
    public function deduct(ServiceContract $contract, string $hours, array $context = [], ?string $actorUserId = null): ContractHourTransaction
    {
        $this->assertAmount($hours);
        if (bccomp($hours, '0', 2) <= 0) {
            throw HourBankException::invalidAmount();
        }

        $entry = DB::transaction(function () use ($contract, $hours, $context, $actorUserId): ContractHourTransaction {
            $locked = $this->lock($contract);

            if ($locked->status !== 'active') {
                throw HourBankException::contractNotActive((string) $locked->status);
            }

            $workOrderId = $context['work_order_id'] ?? null;
            if ($workOrderId !== null) {
                $existing = ContractHourTransaction::query()
                    ->where('service_contract_id', $locked->id)
                    ->where('work_order_id', $workOrderId)
                    ->where('type', ContractHourTransaction::TYPE_DEDUCTION)
                    ->first();
                if ($existing !== null) {
                    return $existing;
                }
            }

            $remaining = $locked->remainingHours();
            if (bccomp($hours, $remaining, 2) > 0) {
                throw HourBankException::insufficientBalance($remaining, $hours);
            }

            $locked->consumed_hours = bcadd((string) $locked->getAttribute('consumed_hours'), $hours, 2);
            $locked->save();

            return $this->record($locked, ContractHourTransaction::TYPE_DEDUCTION, bcmul($hours, '-1', 2), $context, $actorUserId);
        });

        if ($entry->wasRecentlyCreated) {
            event(new ContractHoursDeducted($entry->service_contract_id, $entry->id, (string) $entry->tenant_id));
        }

        return $entry;
    }

    public function topUp(ServiceContract $contract, string $hours, ?string $description = null, ?string $actorUserId = null): ContractHourTransaction
    {
        $this->assertAmount($hours);
        if (bccomp($hours, '0', 2) <= 0) {
            throw HourBankException::invalidAmount();
        }

        return DB::transaction(function () use ($contract, $hours, $description, $actorUserId): ContractHourTransaction {
            $locked = $this->lock($contract);
            $locked->included_hours = bcadd((string) $locked->getAttribute('included_hours'), $hours, 2);
            $locked->low_balance_notified_at = null;
            $locked->save();

            return $this->record($locked, ContractHourTransaction::TYPE_TOP_UP, $hours, ['description' => $description], $actorUserId);
        });
    }

    /**
     * Signed manual correction of the allocated hours; it can never push the remaining balance below zero.
     */
    public function adjust(ServiceContract $contract, string $signedHours, ?string $description = null, ?string $actorUserId = null): ContractHourTransaction
    {
        $this->assertAmount($signedHours);
        if (bccomp($signedHours, '0', 2) === 0) {
            throw HourBankException::invalidAmount();
        }

        return DB::transaction(function () use ($contract, $signedHours, $description, $actorUserId): ContractHourTransaction {
            $locked = $this->lock($contract);
            $newRemaining = bcadd($locked->remainingHours(), $signedHours, 2);
            if (bccomp($newRemaining, '0', 2) < 0) {
                throw HourBankException::insufficientBalance($locked->remainingHours(), bcmul($signedHours, '-1', 2));
            }

            $locked->included_hours = bcadd((string) $locked->getAttribute('included_hours'), $signedHours, 2);
            $locked->save();

            return $this->record($locked, ContractHourTransaction::TYPE_ADJUSTMENT, $signedHours, ['description' => $description], $actorUserId);
        });
    }

    private function lock(ServiceContract $contract): ServiceContract
    {
        return ServiceContract::query()->whereKey($contract->getKey())->lockForUpdate()->firstOrFail();
    }

    private function assertAmount(string $hours): void
    {
        if (preg_match('/^-?\d{1,8}(\.\d{1,2})?$/', $hours) !== 1) {
            throw HourBankException::invalidAmount();
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function record(ServiceContract $contract, string $type, string $signedHours, array $context, ?string $actorUserId): ContractHourTransaction
    {
        return ContractHourTransaction::create([
            'tenant_id' => $contract->tenant_id,
            'service_contract_id' => $contract->id,
            'type' => $type,
            'hours' => $signedHours,
            'balance_after' => $contract->remainingHours(),
            'work_order_id' => $context['work_order_id'] ?? null,
            'ticket_id' => $context['ticket_id'] ?? null,
            'equipment_passport_id' => $context['equipment_passport_id'] ?? null,
            'technician_name' => $context['technician_name'] ?? null,
            'reference' => $context['reference'] ?? null,
            'description' => $context['description'] ?? null,
            'occurred_at' => now(),
            'created_by' => $actorUserId,
        ]);
    }
}
