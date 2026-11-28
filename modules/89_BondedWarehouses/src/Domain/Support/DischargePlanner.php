<?php

declare(strict_types=1);

namespace Modules\BondedWarehouses\Domain\Support;

use Illuminate\Support\Collection;
use Modules\BondedWarehouses\Domain\Enums\AllocationMethod;
use Modules\BondedWarehouses\Domain\Enums\DischargeType;
use Modules\BondedWarehouses\Domain\Exceptions\BondedWarehouseException;
use Modules\BondedWarehouses\Models\BondedMrnLot;

/**
 * Chooses the lots (and the quantity of each) a discharge is taken from, and apportions value, duty, VAT and guarantee to each part.
 * Pure calculation: nothing is written here.
 *
 * FIFO takes the oldest MRN lots first (entry date, then insertion order); Specific_MRN takes exactly the lots the operator named.
 */
final class DischargePlanner
{
    /**
     * @param  Collection<int, BondedMrnLot>  $candidates  the lots the discharge may use (FIFO), or the lots named (Specific_MRN)
     * @param  list<array{mrn_lot_id: string, quantity: float, net_weight_kg?: float|null}>  $specific
     * @return list<array{lot: BondedMrnLot, quantity: float, net_weight_kg: float, released_customs_value: float, duty_amount: float, vat_amount: float, cgu_release_amount: float, paid_duty_amount: float, paid_vat_amount: float, extinguished_duty_amount: float, extinguished_vat_amount: float}>
     *
     * @throws BondedWarehouseException insufficient_remaining_quantity, insufficient_remaining_weight, lot_not_found, quantity_must_be_positive
     */
    public function plan(AllocationMethod $method, DischargeType $type, Collection $candidates, float $quantity, array $specific = []): array
    {
        $parts = $method === AllocationMethod::FIFO
            ? $this->fifo($candidates, $quantity)
            : $this->specific($candidates, $specific);

        return array_map(fn (array $part): array => $this->apportion($part['lot'], $part['quantity'], $part['net_weight_kg'], $type), $parts);
    }

    /**
     * @param  Collection<int, BondedMrnLot>  $candidates
     * @return list<array{lot: BondedMrnLot, quantity: float, net_weight_kg: float|null}>
     */
    private function fifo(Collection $candidates, float $quantity): array
    {
        if ($quantity <= 0.0) {
            throw BondedWarehouseException::because('quantity_must_be_positive');
        }

        $open = $candidates
            ->filter(static fn (BondedMrnLot $lot): bool => (float) $lot->remaining_quantity > 0.0)
            ->sortBy([['entry_date', 'asc'], ['created_at', 'asc'], ['id', 'asc']])
            ->values();

        $available = round((float) $open->sum(static fn (BondedMrnLot $lot): float => (float) $lot->remaining_quantity), 4);

        if ($available + 0.00005 < $quantity) {
            throw BondedWarehouseException::because('insufficient_remaining_quantity', ['available' => $available, 'requested' => $quantity]);
        }

        $left = $quantity;
        $parts = [];

        foreach ($open as $lot) {
            if ($left <= 0.00005) {
                break;
            }

            $take = round(min($left, (float) $lot->remaining_quantity), 4);
            $parts[] = ['lot' => $lot, 'quantity' => $take, 'net_weight_kg' => null];
            $left = round($left - $take, 4);
        }

        return $parts;
    }

    /**
     * @param  Collection<int, BondedMrnLot>  $candidates
     * @param  list<array{mrn_lot_id: string, quantity: float, net_weight_kg?: float|null}>  $specific
     * @return list<array{lot: BondedMrnLot, quantity: float, net_weight_kg: float|null}>
     */
    private function specific(Collection $candidates, array $specific): array
    {
        $byId = $candidates->keyBy(static fn (BondedMrnLot $lot): string => (string) $lot->id);
        $parts = [];
        $used = [];

        foreach ($specific as $row) {
            $lot = $byId->get($row['mrn_lot_id']);

            if ($lot === null) {
                throw BondedWarehouseException::because('lot_not_found', ['lot' => $row['mrn_lot_id']], 404);
            }

            $used[$lot->id] = round(($used[$lot->id] ?? 0.0) + $row['quantity'], 4);

            if ($row['quantity'] <= 0.0) {
                throw BondedWarehouseException::because('quantity_must_be_positive');
            }

            if ($used[$lot->id] > (float) $lot->remaining_quantity + 0.00005) {
                throw BondedWarehouseException::because('insufficient_remaining_quantity', ['mrn' => $lot->mrn_number, 'available' => (float) $lot->remaining_quantity, 'requested' => $used[$lot->id]]);
            }

            $parts[] = ['lot' => $lot, 'quantity' => $row['quantity'], 'net_weight_kg' => $row['net_weight_kg'] ?? null];
        }

        if ($parts === []) {
            throw BondedWarehouseException::because('quantity_must_be_positive');
        }

        return $parts;
    }

    /**
     * @return array{lot: BondedMrnLot, quantity: float, net_weight_kg: float, released_customs_value: float, duty_amount: float, vat_amount: float, cgu_release_amount: float, paid_duty_amount: float, paid_vat_amount: float, extinguished_duty_amount: float, extinguished_vat_amount: float}
     */
    private function apportion(BondedMrnLot $lot, float $quantity, ?float $explicitWeight, DischargeType $type): array
    {
        $initialQty = (float) $lot->initial_quantity;
        $remainingQty = (float) $lot->remaining_quantity;
        $isLast = $quantity >= $remainingQty - 0.00005;

        $weight = $explicitWeight ?? ($isLast
            ? (float) $lot->remaining_net_weight_kg
            : ProportionalShare::of((float) $lot->initial_net_weight_kg, $initialQty, $remainingQty, $quantity, 3));

        if ($weight > (float) $lot->remaining_net_weight_kg + 0.0005) {
            throw BondedWarehouseException::because('insufficient_remaining_weight', ['mrn' => $lot->mrn_number, 'available' => (float) $lot->remaining_net_weight_kg, 'requested' => $weight]);
        }

        $value = ProportionalShare::of((float) $lot->customs_statistical_value, $initialQty, $remainingQty, $quantity);
        $duty = ProportionalShare::of((float) $lot->suspended_duty_amount, $initialQty, $remainingQty, $quantity);
        $vat = ProportionalShare::of((float) $lot->suspended_vat_amount, $initialQty, $remainingQty, $quantity);
        $release = $type === DischargeType::UsualHandlingAnnex7103
            ? 0.0
            : min((float) $lot->cgu_engaged_amount, round($duty + $vat, 2));

        $due = $type === DischargeType::IM4FreeCirculation;
        $debt = $type !== DischargeType::UsualHandlingAnnex7103;

        return [
            'lot' => $lot,
            'quantity' => $quantity,
            'net_weight_kg' => round($weight, 3),
            'released_customs_value' => $value,
            'duty_amount' => $duty,
            'vat_amount' => $vat,
            'cgu_release_amount' => $release,
            'paid_duty_amount' => $due ? $duty : 0.0,
            'paid_vat_amount' => $due ? $vat : 0.0,
            'extinguished_duty_amount' => $debt && ! $due ? $duty : 0.0,
            'extinguished_vat_amount' => $debt && ! $due ? $vat : 0.0,
        ];
    }
}
