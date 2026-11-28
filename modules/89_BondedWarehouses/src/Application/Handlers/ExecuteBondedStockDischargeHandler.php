<?php

declare(strict_types=1);

namespace Modules\BondedWarehouses\Application\Handlers;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\BondedWarehouses\Application\Commands\ExecuteBondedStockDischargeCommand;
use Modules\BondedWarehouses\Application\Services\CguGuaranteeService;
use Modules\BondedWarehouses\Domain\Enums\AllocationMethod;
use Modules\BondedWarehouses\Domain\Enums\CguReferenceType;
use Modules\BondedWarehouses\Domain\Enums\CustomsStatus;
use Modules\BondedWarehouses\Domain\Enums\DischargeType;
use Modules\BondedWarehouses\Domain\Enums\RegimeType;
use Modules\BondedWarehouses\Domain\Enums\UsualHandlingOperation;
use Modules\BondedWarehouses\Domain\Exceptions\BondedWarehouseException;
use Modules\BondedWarehouses\Domain\Support\CustomsCodes;
use Modules\BondedWarehouses\Domain\Support\DischargePlanner;
use Modules\BondedWarehouses\Events\BondedStockDischarged;
use Modules\BondedWarehouses\Models\BondedMrnLot;
use Modules\BondedWarehouses\Models\CustomsDischargeMovement;
use Modules\BondedWarehouses\Models\CustomsWarehouseAuthorization;
use Modules\Inventory\Models\StockLot;

/**
 * Discharges bonded stock (FIFO over the oldest MRN lots, or Specific_MRN), apportioning value, duty, VAT and guarantee to the quantity:
 * duties and VAT become due on IM4 (free circulation), are extinguished on EX3 / export / T1, and a usual handling (Annex 71-03 CDU) creates no
 * customs debt and keeps the MRN lineage. Returns the plan and the customs summary (the data of the Mod 031 / Mod 198 clearing workflow).
 */
final class ExecuteBondedStockDischargeHandler
{
    public function __construct(
        private readonly CguGuaranteeService $guarantee,
        private readonly DischargePlanner $planner = new DischargePlanner,
    ) {}

    /**
     * @return array{simulated: bool, lines: list<array<string, mixed>>, summary: array<string, mixed>, movement_ids: list<string>}
     *
     * @throws BondedWarehouseException
     */
    public function handle(ExecuteBondedStockDischargeCommand $command): array
    {
        $this->assertCommand($command);

        return DB::transaction(function () use ($command): array {
            $authorization = CustomsWarehouseAuthorization::query()->find($command->authorizationId)
                ?? throw BondedWarehouseException::because('authorization_not_found', [], 404);

            $date = Carbon::parse($command->dischargeDate);

            if (! $authorization->isUsableOn($date)) {
                throw BondedWarehouseException::because('authorization_inactive', ['code' => $authorization->authorization_code]);
            }

            if ($authorization->regime_type === RegimeType::VatDepotArt50Bis) {
                throw BondedWarehouseException::because('regime_not_customs_warehouse');
            }

            $plan = $this->planner->plan($command->allocationMethod, $command->dischargeType, $this->candidates($command), (float) ($command->quantity ?? 0), $command->allocations);

            foreach ($plan as $part) {
                if ($date->lt($part['lot']->entry_date)) {
                    throw BondedWarehouseException::because('discharge_before_entry', ['mrn' => $part['lot']->mrn_number]);
                }
            }

            $lines = $this->lines($plan);
            $movementIds = $command->simulate ? [] : $this->persist($command, $authorization, $plan);

            return [
                'simulated' => $command->simulate,
                'lines' => $lines,
                'summary' => $this->summary($command, $authorization, $plan),
                'movement_ids' => $movementIds,
            ];
        });
    }

    private function assertCommand(ExecuteBondedStockDischargeCommand $command): void
    {
        if ($command->dischargeType === DischargeType::UsualHandlingAnnex7103) {
            if (! in_array($command->usualHandlingCode, UsualHandlingOperation::values(), true)) {
                throw BondedWarehouseException::because('usual_handling_code_required');
            }
        } elseif (! $command->simulate && ($command->dischargeMrnNumber === null || ! CustomsCodes::isValidMrn($command->dischargeMrnNumber))) {
            throw BondedWarehouseException::because('invalid_discharge_mrn');
        }

        if ($command->allocationMethod === AllocationMethod::FIFO && ($command->productId === null || $command->quantity === null)) {
            throw BondedWarehouseException::because('fifo_needs_product_and_quantity');
        }
    }

    /**
     * @return Collection<int, BondedMrnLot>
     */
    private function candidates(ExecuteBondedStockDischargeCommand $command): Collection
    {
        $query = BondedMrnLot::query()
            ->where('authorization_id', $command->authorizationId)
            ->where('remaining_quantity', '>', 0)
            ->lockForUpdate();

        if ($command->allocationMethod === AllocationMethod::FIFO) {
            $query->where('product_id', $command->productId);
        } else {
            $query->whereIn('id', array_column($command->allocations, 'mrn_lot_id'));
        }

        return $query->get();
    }

    /**
     * @param  list<array<string, mixed>>  $plan
     * @return list<string>
     */
    private function persist(ExecuteBondedStockDischargeCommand $command, CustomsWarehouseAuthorization $authorization, array $plan): array
    {
        $ids = [];
        $released = 0.0;
        $usual = $command->dischargeType === DischargeType::UsualHandlingAnnex7103;

        foreach ($plan as $part) {
            /** @var BondedMrnLot $lot */
            $lot = $part['lot'];

            $movement = CustomsDischargeMovement::query()->create([
                'tenant_id' => $authorization->tenant_id,
                'authorization_id' => $authorization->id,
                'mrn_lot_id' => $lot->id,
                'discharge_type' => $command->dischargeType,
                'discharge_mrn_number' => $command->dischargeMrnNumber !== null ? CustomsCodes::normalizeMrn($command->dischargeMrnNumber) : null,
                'discharge_date' => $command->dischargeDate,
                'allocation_method' => $command->allocationMethod,
                'discharged_quantity' => $part['quantity'],
                'discharged_net_weight_kg' => $part['net_weight_kg'],
                'released_customs_value' => $part['released_customs_value'],
                'paid_duty_amount' => $part['paid_duty_amount'],
                'paid_vat_amount' => $part['paid_vat_amount'],
                'extinguished_duty_amount' => $part['extinguished_duty_amount'],
                'extinguished_vat_amount' => $part['extinguished_vat_amount'],
                'cgu_released_amount' => $part['cgu_release_amount'],
                'usual_handling_code' => $usual ? $command->usualHandlingCode : null,
                'usual_handling_notes' => $usual ? $command->usualHandlingNotes : null,
                'customs_prospectus_document_id' => $command->customsProspectusDocumentId,
                'created_by' => $command->userId,
            ]);
            $ids[] = (string) $movement->id;

            if ($usual) {
                $lot->forceFill(['customs_status' => CustomsStatus::UsualHandlingInProgress])->save();
            } else {
                $remaining = round((float) $lot->remaining_quantity - $part['quantity'], 4);
                $done = $remaining <= 0.00005;

                $lot->forceFill([
                    'remaining_quantity' => $done ? 0 : $remaining,
                    'remaining_net_weight_kg' => $done ? 0 : max(0.0, round((float) $lot->remaining_net_weight_kg - $part['net_weight_kg'], 3)),
                    'remaining_gross_weight_kg' => $done ? 0 : max(0.0, round((float) $lot->remaining_gross_weight_kg - $this->grossShare($lot, $part['quantity']), 3)),
                    'cgu_engaged_amount' => $done ? 0 : max(0.0, round((float) $lot->cgu_engaged_amount - $part['cgu_release_amount'], 2)),
                    'customs_status' => $done ? CustomsStatus::FullyDischarged : CustomsStatus::PartiallyDischarged,
                ])->save();

                $this->guarantee->release((string) $authorization->id, CguReferenceType::MRNDischarge, (string) $movement->id, $part['duty_amount'], $part['vat_amount'], $part['cgu_release_amount']);
                $released += $part['cgu_release_amount'];
            }

            if ($lot->wms_lot_id !== null) {
                StockLot::query()->whereKey($lot->wms_lot_id)->update(['customs_status' => $lot->customs_status->value]);
            }
        }

        event(new BondedStockDischarged((string) $authorization->id, $command->dischargeType->value, $ids, round($released, 2)));

        return $ids;
    }

    private function grossShare(BondedMrnLot $lot, float $quantity): float
    {
        $remaining = (float) $lot->remaining_quantity;

        return $remaining <= 0.0 ? 0.0 : (float) $lot->remaining_gross_weight_kg * min(1.0, $quantity / $remaining);
    }

    /**
     * @param  list<array<string, mixed>>  $plan
     * @return list<array<string, mixed>>
     */
    private function lines(array $plan): array
    {
        return array_map(static function (array $part): array {
            /** @var BondedMrnLot $lot */
            $lot = $part['lot'];

            return [
                'mrn_lot_id' => (string) $lot->id,
                'mrn_number' => $lot->mrn_number,
                'mrn_item_number' => $lot->mrn_item_number,
                'taric_hs_code' => $lot->taric_hs_code,
                'country_of_origin' => $lot->country_of_origin,
                'entry_date' => $lot->entry_date->toDateString(),
                'quantity' => $part['quantity'],
                'net_weight_kg' => $part['net_weight_kg'],
                'remaining_quantity_after' => round(max(0.0, (float) $lot->remaining_quantity - $part['quantity']), 4),
                'released_customs_value' => $part['released_customs_value'],
                'duty_amount' => $part['duty_amount'],
                'vat_amount' => $part['vat_amount'],
                'paid_duty_amount' => $part['paid_duty_amount'],
                'paid_vat_amount' => $part['paid_vat_amount'],
                'extinguished_duty_amount' => $part['extinguished_duty_amount'],
                'extinguished_vat_amount' => $part['extinguished_vat_amount'],
                'cgu_release_amount' => $part['cgu_release_amount'],
            ];
        }, $plan);
    }

    /**
     * @param  list<array<string, mixed>>  $plan
     * @return array<string, mixed>
     */
    private function summary(ExecuteBondedStockDischargeCommand $command, CustomsWarehouseAuthorization $authorization, array $plan): array
    {
        $sum = static fn (string $key): float => round(array_sum(array_column($plan, $key)), 2);

        return [
            'authorization_id' => (string) $authorization->id,
            'authorization_code' => $authorization->authorization_code,
            'customs_office_code' => $authorization->customs_office_code,
            'discharge_type' => $command->dischargeType->value,
            'allocation_method' => $command->allocationMethod->value,
            'discharge_date' => $command->dischargeDate,
            'discharge_mrn_number' => $command->dischargeMrnNumber,
            'currency' => $authorization->currency,
            'total_quantity' => round(array_sum(array_column($plan, 'quantity')), 4),
            'total_net_weight_kg' => round(array_sum(array_column($plan, 'net_weight_kg')), 3),
            'total_customs_value' => $sum('released_customs_value'),
            'total_duty_due' => $sum('paid_duty_amount'),
            'total_vat_due' => $sum('paid_vat_amount'),
            'total_duty_extinguished' => $sum('extinguished_duty_amount'),
            'total_vat_extinguished' => $sum('extinguished_vat_amount'),
            'total_cgu_released' => $sum('cgu_release_amount'),
            'customs_debt_arises' => $command->dischargeType === DischargeType::IM4FreeCirculation,
            'customs_prospectus_document_id' => $command->customsProspectusDocumentId,
            'lots' => count($plan),
        ];
    }
}
