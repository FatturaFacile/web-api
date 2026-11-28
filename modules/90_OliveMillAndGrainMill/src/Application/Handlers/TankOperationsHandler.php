<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Application\Handlers;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\OliveMillAndGrainMill\Application\Commands\AdjustTankStockCommand;
use Modules\OliveMillAndGrainMill\Application\Commands\PackageFromTankCommand;
use Modules\OliveMillAndGrainMill\Application\Commands\TransferBetweenTanksCommand;
use Modules\OliveMillAndGrainMill\Application\Services\SianRegister;
use Modules\OliveMillAndGrainMill\Application\Services\TankLedger;
use Modules\OliveMillAndGrainMill\Domain\Enums\OriginDesignation;
use Modules\OliveMillAndGrainMill\Domain\Enums\SianCausalCode;
use Modules\OliveMillAndGrainMill\Domain\Exceptions\OliveMillException;
use Modules\OliveMillAndGrainMill\Domain\ValueObjects\OilDensityConverter;
use Modules\OliveMillAndGrainMill\Models\SianMovement;
use Modules\OliveMillAndGrainMill\Models\SianTank;

/**
 * Operations between tanks: transfer and blend (T01), bottling (S03) and rectification of a stock (R01). Each one moves the stock, applies the
 * blending rules and writes the SIAN operation in one transaction.
 */
final class TankOperationsHandler
{
    public function __construct(private readonly TankLedger $tanks, private readonly SianRegister $register) {}

    /**
     * @return array{movement: SianMovement, downgraded: bool, from: SianTank, to: SianTank}
     *
     * @throws OliveMillException same_tank, tank_empty, insufficient_tank_stock, tank_capacity_exceeded, dop_igp_mixing, bio_segregation
     */
    public function transfer(TransferBetweenTanksCommand $command): array
    {
        if ($command->fromTankId === $command->toTankId) {
            throw OliveMillException::because('same_tank');
        }

        if ($command->quantityKg <= 0.0) {
            throw OliveMillException::because('quantity_must_be_positive');
        }

        return DB::transaction(function () use ($command): array {
            // Always lock in the same order so two opposite transfers cannot deadlock.
            $ids = [$command->fromTankId, $command->toTankId];
            sort($ids);
            $locked = [];
            foreach ($ids as $id) {
                $locked[$id] = $this->tanks->lock($id);
            }
            $from = $locked[$command->fromTankId];
            $to = $locked[$command->toTankId];

            if ($from->category === null || (float) $from->stock_kg <= 0.0005) {
                throw OliveMillException::because('tank_empty', ['code' => $from->code]);
            }

            $category = $from->category;
            $origin = $from->origin_designation;
            $lots = $this->tanks->unload($from, $command->quantityKg);
            $downgraded = $this->tanks->load($to, $command->quantityKg, $category, $origin ?? OriginDesignation::Italia, (string) array_key_first($lots), $lots);

            $movement = $this->register->record(SianCausalCode::T01, $this->date($command->operationDate), [
                'from_tank_id' => (string) $from->id,
                'to_tank_id' => (string) $to->id,
                'quantity_kg' => $command->quantityKg,
                'category' => $to->category,
                'origin' => $to->origin_designation,
                'lot_code' => (string) array_key_first($lots),
                'notes' => $command->notes,
                'source_type' => 'tank_transfer',
                'user_id' => $command->userId,
            ]);

            return ['movement' => $movement, 'downgraded' => $downgraded, 'from' => $from, 'to' => $to];
        });
    }

    /**
     * @return array{movement: SianMovement, tank: SianTank}
     *
     * @throws OliveMillException tank_empty, insufficient_tank_stock
     */
    public function package(PackageFromTankCommand $command): array
    {
        if ($command->bottleCount < 1) {
            throw OliveMillException::because('quantity_must_be_positive');
        }

        return DB::transaction(function () use ($command): array {
            $tank = $this->tanks->lock($command->tankId);

            if ($tank->category === null || (float) $tank->stock_kg <= 0.0005) {
                throw OliveMillException::because('tank_empty', ['code' => $tank->code]);
            }

            $date = $this->date($command->operationDate);
            $liters = round($command->bottleFormat->liters() * $command->bottleCount, 3);
            $kg = OilDensityConverter::fromConfig()->toKg($liters);
            $category = $tank->category;
            $origin = $tank->origin_designation;
            $lots = $this->tanks->unload($tank, $kg);

            $movement = $this->register->record(SianCausalCode::S03, $date, [
                'from_tank_id' => (string) $tank->id,
                'quantity_kg' => $kg,
                'category' => $category,
                'origin' => $origin,
                'lot_code' => (string) array_key_first($lots),
                'bottle_format' => $command->bottleFormat->value,
                'bottle_count' => $command->bottleCount,
                'package_lot' => $command->packageLot ?? $this->packageLot($tank, $date),
                'best_before' => $date->copy()->addMonths((int) config('olive_mill.best_before_months', 18))->toDateString(),
                'notes' => $command->notes,
                'source_type' => 'packaging',
                'user_id' => $command->userId,
            ]);

            return ['movement' => $movement, 'tank' => $tank];
        });
    }

    /**
     * @return array{movement: SianMovement, tank: SianTank}
     *
     * @throws OliveMillException tank_empty, insufficient_tank_stock
     */
    public function adjust(AdjustTankStockCommand $command): array
    {
        if ($command->quantityKg <= 0.0) {
            throw OliveMillException::because('quantity_must_be_positive');
        }

        return DB::transaction(function () use ($command): array {
            $tank = $this->tanks->lock($command->tankId);

            if ($tank->category === null) {
                throw OliveMillException::because('tank_empty', ['code' => $tank->code]);
            }

            $category = $tank->category;
            $origin = $tank->origin_designation;
            $lots = $this->tanks->unload($tank, $command->quantityKg);

            $movement = $this->register->record(SianCausalCode::R01, $this->date($command->operationDate), [
                'from_tank_id' => (string) $tank->id,
                'quantity_kg' => $command->quantityKg,
                'category' => $category,
                'origin' => $origin,
                'lot_code' => (string) array_key_first($lots),
                'notes' => $command->reason,
                'source_type' => 'tank_adjustment',
                'user_id' => $command->userId,
            ]);

            return ['movement' => $movement, 'tank' => $tank];
        });
    }

    private function date(?string $date): Carbon
    {
        return $date === null ? now() : Carbon::parse($date);
    }

    private function packageLot(SianTank $tank, Carbon $date): string
    {
        $sequence = SianMovement::query()->where('causal', SianCausalCode::S03->value)->where('from_tank_id', $tank->id)->whereDate('operation_date', $date->toDateString())->count() + 1;

        return sprintf('L%s-%s-%02d', $date->format('ymd'), $tank->code, $sequence);
    }
}
