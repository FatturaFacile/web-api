<?php

declare(strict_types=1);

namespace Modules\BondedWarehouses\Application\Services;

use Illuminate\Support\Facades\DB;
use Modules\BondedWarehouses\Domain\Enums\CguDirection;
use Modules\BondedWarehouses\Domain\Enums\CguReferenceType;
use Modules\BondedWarehouses\Domain\Exceptions\BondedWarehouseException;
use Modules\BondedWarehouses\Models\CguLedgerEntry;
use Modules\BondedWarehouses\Models\CustomsWarehouseAuthorization;

/**
 * Engages and releases the comprehensive guarantee (CGU) ceiling of an authorization. Every change locks the authorization row,
 * appends one ledger entry with the running balance and the remaining plafond, and updates the utilized amount, in one transaction.
 */
final class CguGuaranteeService
{
    /**
     * @throws BondedWarehouseException authorization_not_found, cgu_ceiling_exceeded
     */
    public function engage(string $authorizationId, CguReferenceType $referenceType, ?string $referenceId, float $duty, float $vat): CguLedgerEntry
    {
        return DB::transaction(function () use ($authorizationId, $referenceType, $referenceId, $duty, $vat): CguLedgerEntry {
            $authorization = $this->lock($authorizationId);
            $delta = round($duty + $vat, 2);
            $utilized = round((float) $authorization->cgu_utilized_amount + $delta, 2);
            $ceiling = (float) $authorization->cgu_max_ceiling_amount;

            if ($utilized > $ceiling + 0.004) {
                throw BondedWarehouseException::because('cgu_ceiling_exceeded', [
                    'ceiling' => number_format($ceiling, 2, '.', ''),
                    'utilized' => $authorization->cgu_utilized_amount,
                    'requested' => number_format($delta, 2, '.', ''),
                    'available' => $authorization->availablePlafond(),
                    'currency' => $authorization->currency,
                ]);
            }

            return $this->append($authorization, $referenceType, $referenceId, CguDirection::Engage, $duty, $vat, $delta, $utilized);
        });
    }

    /**
     * @throws BondedWarehouseException authorization_not_found, cgu_release_exceeds_utilized
     */
    public function release(string $authorizationId, CguReferenceType $referenceType, ?string $referenceId, float $duty, float $vat, ?float $total = null): CguLedgerEntry
    {
        return DB::transaction(function () use ($authorizationId, $referenceType, $referenceId, $duty, $vat, $total): CguLedgerEntry {
            $authorization = $this->lock($authorizationId);
            $delta = round($total ?? $duty + $vat, 2);
            $utilized = round((float) $authorization->cgu_utilized_amount - $delta, 2);

            if ($utilized < -0.004) {
                throw BondedWarehouseException::because('cgu_release_exceeds_utilized', ['utilized' => $authorization->cgu_utilized_amount, 'requested' => number_format($delta, 2, '.', '')]);
            }

            return $this->append($authorization, $referenceType, $referenceId, CguDirection::Release, $duty, $vat, -$delta, max(0.0, $utilized));
        });
    }

    private function lock(string $authorizationId): CustomsWarehouseAuthorization
    {
        $authorization = CustomsWarehouseAuthorization::query()->whereKey($authorizationId)->lockForUpdate()->first();

        if ($authorization === null) {
            throw BondedWarehouseException::because('authorization_not_found', [], 404);
        }

        return $authorization;
    }

    private function append(CustomsWarehouseAuthorization $authorization, CguReferenceType $referenceType, ?string $referenceId, CguDirection $direction, float $duty, float $vat, float $signedDelta, float $utilized): CguLedgerEntry
    {
        $authorization->forceFill(['cgu_utilized_amount' => number_format($utilized, 2, '.', '')])->save();

        return CguLedgerEntry::query()->create([
            'tenant_id' => $authorization->tenant_id,
            'authorization_id' => $authorization->id,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'movement_direction' => $direction,
            'duty_amount' => number_format($duty, 2, '.', ''),
            'vat_amount' => number_format($vat, 2, '.', ''),
            'total_cgu_delta' => number_format($signedDelta, 2, '.', ''),
            'running_utilized_balance' => number_format($utilized, 2, '.', ''),
            'remaining_plafond' => number_format(max(0.0, (float) $authorization->cgu_max_ceiling_amount - $utilized), 2, '.', ''),
            'recorded_at' => now(),
        ]);
    }
}
