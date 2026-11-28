<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Application\Handlers;

use App\Models\Contact;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\OliveMillAndGrainMill\Application\Commands\CompleteMillingCycleCommand;
use Modules\OliveMillAndGrainMill\Application\Services\OilProfile;
use Modules\OliveMillAndGrainMill\Application\Services\SianRegister;
use Modules\OliveMillAndGrainMill\Application\Services\TankLedger;
use Modules\OliveMillAndGrainMill\Domain\Enums\Commodity;
use Modules\OliveMillAndGrainMill\Domain\Enums\CycleStatus;
use Modules\OliveMillAndGrainMill\Domain\Enums\DeliveryStatus;
use Modules\OliveMillAndGrainMill\Domain\Enums\ProcessingMode;
use Modules\OliveMillAndGrainMill\Domain\Enums\SianCausalCode;
use Modules\OliveMillAndGrainMill\Domain\Exceptions\OliveMillException;
use Modules\OliveMillAndGrainMill\Domain\Services\YieldCalculationService;
use Modules\OliveMillAndGrainMill\Domain\ValueObjects\AcidityProfile;
use Modules\OliveMillAndGrainMill\Domain\ValueObjects\OilDensityConverter;
use Modules\OliveMillAndGrainMill\Events\MillingCycleCompleted;
use Modules\OliveMillAndGrainMill\Models\MillingCycle;
use Modules\OliveMillAndGrainMill\Models\OliveDelivery;

/**
 * Closes a milling cycle: yield %, cold extraction flag and quality class, the by-products, and the routing of the oil (or flour) to the farmer,
 * to the molenda settlement and to the mill tanks, loading the tank and writing the SIAN operations in the same transaction. For the mill's own
 * account the whole oil goes to the tank (C01); for a farmer the stored oil is a C02 load and the oil handed over at the press an S02 return.
 */
final class CompleteMillingCycleHandler
{
    private const float TOLERANCE_KG = 0.002;

    public function __construct(
        private readonly TankLedger $tanks,
        private readonly SianRegister $register,
        private readonly OilProfile $profile,
    ) {}

    /**
     * @throws OliveMillException
     */
    public function handle(CompleteMillingCycleCommand $command): MillingCycle
    {
        return DB::transaction(function () use ($command): MillingCycle {
            $cycle = MillingCycle::query()->whereKey($command->cycleId)->lockForUpdate()->first() ?? throw OliveMillException::because('cycle_not_found', [], 404);

            if ($cycle->status !== CycleStatus::InProgress) {
                throw OliveMillException::because('cycle_not_open', ['status' => $cycle->status->value]);
            }

            $delivery = OliveDelivery::query()->whereKey($cycle->delivery_id)->lockForUpdate()->firstOrFail();
            $isOil = $delivery->commodity === Commodity::Olive;
            $ownAccount = $delivery->processing_mode === ProcessingMode::ContoProprio;
            $density = OilDensityConverter::fromConfig();

            $outputKg = $command->outputKg ?? ($command->outputLiters === null ? null : $density->toKg($command->outputLiters))
                ?? throw OliveMillException::because('output_required');
            $temperature = $command->malaxationTemperatureC ?? ($cycle->malaxation_temperature_c === null ? null : (float) $cycle->malaxation_temperature_c);
            $lab = new AcidityProfile($command->acidityPct, $command->peroxides, $command->polyphenolsMgKg);

            $evaluation = YieldCalculationService::fromConfig()->evaluate((float) $cycle->input_net_kg, $outputKg, $temperature, $lab, $delivery->certification, $isOil);
            [$pickup, $molenda, $stock] = $this->destinations($command, $outputKg, $ownAccount);

            if ($stock > 0.0 && $isOil && $command->targetTankId === null) {
                throw OliveMillException::because('target_tank_required');
            }

            $cycle->forceFill([
                'status' => $ownAccount ? CycleStatus::Settled : CycleStatus::Completed,
                'ended_at' => $command->endedAt === null ? now() : Carbon::parse($command->endedAt),
                'completed_at' => now(),
                'malaxation_temperature_c' => $temperature,
                'water_added_l' => $command->waterAddedL ?? $cycle->water_added_l,
                'output_kg' => $outputKg,
                'output_liters' => $evaluation['output_liters'],
                'yield_percent' => $evaluation['yield_percent'],
                'cold_extraction' => $evaluation['cold_extraction'],
                'acidity_pct' => $command->acidityPct,
                'peroxides' => $command->peroxides,
                'polyphenols_mg_kg' => $command->polyphenolsMgKg,
                'panel_notes' => $command->panelNotes,
                'quality_class' => $evaluation['quality_class'],
                'quality_warnings' => $evaluation['warnings'],
                'sansa_kg' => $command->sansaKg ?? 0,
                'nocciolino_kg' => $command->nocciolinoKg ?? 0,
                'vegetation_water_l' => $command->vegetationWaterL ?? 0,
                'bran_kg' => $command->branKg ?? 0,
                'pickup_kg' => $pickup,
                'molenda_kg' => $molenda,
                'mill_stock_kg' => $stock,
                'target_tank_id' => $command->targetTankId,
            ])->save();

            if ($isOil) {
                $this->route($cycle, $delivery, $command, $pickup, $stock, $ownAccount);
            }

            $delivery->forceFill(['status' => $ownAccount ? DeliveryStatus::Settled : DeliveryStatus::Milled])->save();

            event(new MillingCycleCompleted((string) $cycle->id, (string) $delivery->id, $evaluation['yield_percent'], $outputKg));

            return $cycle->refresh();
        });
    }

    /**
     * Pickup, molenda (left at the mill for the settlement) and stock (kept in a mill tank) of the product; they must add up to the output.
     *
     * @return array{0: float, 1: float, 2: float}
     *
     * @throws OliveMillException own_account_no_farmer_share, destination_mismatch
     */
    private function destinations(CompleteMillingCycleCommand $command, float $outputKg, bool $ownAccount): array
    {
        $given = $command->pickupKg !== null || $command->molendaKg !== null || $command->millStockKg !== null;
        $pickup = $command->pickupKg ?? 0.0;
        $molenda = $command->molendaKg ?? 0.0;
        $stock = $command->millStockKg ?? 0.0;

        if (! $given) {
            return $ownAccount ? [0.0, 0.0, $outputKg] : [$outputKg, 0.0, 0.0];
        }

        if ($ownAccount && ($pickup > 0.0 || $molenda > 0.0)) {
            throw OliveMillException::because('own_account_no_farmer_share');
        }

        if (abs($pickup + $molenda + $stock - $outputKg) > self::TOLERANCE_KG) {
            throw OliveMillException::because('destination_mismatch', ['sum' => round($pickup + $molenda + $stock, 3), 'output' => $outputKg]);
        }

        return [round($pickup, 3), round($molenda, 3), round($stock, 3)];
    }

    private function route(MillingCycle $cycle, OliveDelivery $delivery, CompleteMillingCycleCommand $command, float $pickup, float $stock, bool $ownAccount): void
    {
        $category = $this->profile->category($delivery, $cycle);
        $origin = $this->profile->origin($delivery, $command->originDesignation);
        $farmer = $ownAccount || $delivery->farmer_contact_id === null ? null : Contact::query()->find($delivery->farmer_contact_id);
        $common = ['category' => $category, 'origin' => $origin, 'lot_code' => $delivery->lot_code, 'source_type' => 'milling_cycle', 'source_id' => (string) $cycle->id, 'user_id' => $command->userId];

        if ($stock > 0.0 && $command->targetTankId !== null) {
            $tank = $this->tanks->lock($command->targetTankId);
            $this->tanks->load($tank, $stock, $category, $origin, $delivery->lot_code);
            $this->register->record($ownAccount ? SianCausalCode::C01 : SianCausalCode::C02, now(), $common + ['to_tank_id' => (string) $tank->id, 'quantity_kg' => $stock, 'counterparty' => $farmer]);
        }

        if (! $ownAccount && $pickup > 0.0) {
            $this->register->record(SianCausalCode::S02, now(), $common + ['quantity_kg' => $pickup, 'counterparty' => $farmer]);
        }
    }
}
