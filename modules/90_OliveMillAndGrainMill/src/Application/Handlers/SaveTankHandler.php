<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Application\Handlers;

use Illuminate\Support\Facades\DB;
use Modules\OliveMillAndGrainMill\Domain\Exceptions\OliveMillException;
use Modules\OliveMillAndGrainMill\Domain\ValueObjects\OilDensityConverter;
use Modules\OliveMillAndGrainMill\Models\SianMovement;
use Modules\OliveMillAndGrainMill\Models\SianTank;

/**
 * Creates, edits and removes the tanks of the mill. The stock, the category and the lots of a tank only change through the register operations.
 */
final class SaveTankHandler
{
    /**
     * @param  array<string, mixed>  $data  validated request data
     *
     * @throws OliveMillException tank_not_found, capacity_below_stock, tank_in_use
     */
    public function handle(array $data, ?string $id = null): SianTank
    {
        return DB::transaction(function () use ($data, $id): SianTank {
            $tank = $id === null ? new SianTank : (SianTank::query()->whereKey($id)->lockForUpdate()->first() ?? throw OliveMillException::because('tank_not_found', [], 404));
            $capacityKg = (float) $data['capacity_kg'];

            if ($tank->exists && $capacityKg < (float) $tank->stock_kg - 0.0005) {
                throw OliveMillException::because('capacity_below_stock', ['stock' => $tank->stock_kg]);
            }

            if ($tank->exists && ($data['is_active'] ?? true) === false && (float) $tank->stock_kg > 0.0005) {
                throw OliveMillException::because('tank_in_use', ['stock' => $tank->stock_kg]);
            }

            $tank->fill([
                'code' => (string) $data['code'],
                'description' => $data['description'] ?? null,
                'material' => (string) ($data['material'] ?? 'INOX_AISI_304'),
                'capacity_kg' => $capacityKg,
                'capacity_l' => isset($data['capacity_l']) ? (float) $data['capacity_l'] : OilDensityConverter::fromConfig()->toLiters($capacityKg),
                'temperature_c' => $data['temperature_c'] ?? null,
                'is_active' => (bool) ($data['is_active'] ?? true),
                'grid_row' => (int) ($data['grid_row'] ?? 1),
                'grid_col' => (int) ($data['grid_col'] ?? 1),
                'zone_label' => $data['zone_label'] ?? null,
            ]);
            $tank->save();

            return $tank->refresh();
        });
    }

    /**
     * @throws OliveMillException tank_not_found, tank_in_use
     */
    public function delete(string $id): void
    {
        DB::transaction(function () use ($id): void {
            $tank = SianTank::query()->whereKey($id)->lockForUpdate()->first() ?? throw OliveMillException::because('tank_not_found', [], 404);

            $used = (float) $tank->stock_kg > 0.0005
                || SianMovement::query()->where(static fn ($query) => $query->where('from_tank_id', $id)->orWhere('to_tank_id', $id))->exists();

            if ($used) {
                throw OliveMillException::because('tank_in_use', ['stock' => $tank->stock_kg]);
            }

            $tank->delete();
        });
    }
}
