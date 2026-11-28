<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Application\Services;

use Modules\OliveMillAndGrainMill\Domain\Enums\OilCategory;
use Modules\OliveMillAndGrainMill\Domain\Enums\OriginDesignation;
use Modules\OliveMillAndGrainMill\Domain\Exceptions\OliveMillException;
use Modules\OliveMillAndGrainMill\Domain\Services\SianComplianceService;
use Modules\OliveMillAndGrainMill\Domain\Support\TankComposition;
use Modules\OliveMillAndGrainMill\Domain\ValueObjects\OilDensityConverter;
use Modules\OliveMillAndGrainMill\Models\SianTank;

/**
 * Stock of the tanks: every load and unload goes through here so the capacity, the category and origin blending rules and the lot
 * composition are applied the same way for the milling, the settlements, the transfers and the bottling. Call inside a transaction.
 */
final class TankLedger
{
    public function __construct(private readonly SianComplianceService $compliance) {}

    /**
     * Locks an active tank of the company.
     *
     * @throws OliveMillException tank_not_found, tank_inactive
     */
    public function lock(string $tankId): SianTank
    {
        $tank = SianTank::query()->whereKey($tankId)->lockForUpdate()->first() ?? throw OliveMillException::because('tank_not_found', [], 404);

        if (! $tank->is_active) {
            throw OliveMillException::because('tank_inactive', ['code' => $tank->code]);
        }

        return $tank;
    }

    /**
     * Pours oil into a tank. Returns whether the blend lowered the category of the oil.
     *
     * @param  array<string, float>|null  $composition  lots poured in; defaults to the single lot given
     *
     * @throws OliveMillException tank_capacity_exceeded, dop_igp_mixing, bio_segregation, origin_designation_mixing
     */
    public function load(SianTank $tank, float $kg, OilCategory $category, OriginDesignation $origin, string $lot, ?array $composition = null): bool
    {
        $stock = (float) $tank->stock_kg;
        $this->compliance->assertCapacity((float) $tank->capacity_kg, $stock, $kg);
        $mix = $this->compliance->mixCategory($tank->category, $stock, $category);
        $resultOrigin = $this->compliance->mixOrigin($tank->origin_designation, $stock, $origin);
        $converter = OilDensityConverter::fromConfig();

        $merged = $stock <= 0.0005 ? [] : (array) ($tank->lot_composition ?? []);
        $merged = TankComposition::merge($merged, $composition ?? [$lot => $kg]);

        $newStock = round($stock + $kg, 3);
        $tank->stock_kg = (string) $newStock;
        $tank->stock_l = (string) $converter->toLiters($newStock);
        $tank->category = $mix['category'];
        $tank->origin_designation = $resultOrigin;
        $tank->lot_composition = $merged;
        $tank->save();

        return $mix['downgraded'];
    }

    /**
     * Draws oil from a tank and returns the lots that left with it. An emptied tank forgets its category, origin and lots.
     *
     * @return array<string, float>
     *
     * @throws OliveMillException insufficient_tank_stock
     */
    public function unload(SianTank $tank, float $kg): array
    {
        $stock = (float) $tank->stock_kg;
        $this->compliance->assertStock($stock, $kg);

        $split = TankComposition::withdraw((array) ($tank->lot_composition ?? []), $kg);
        $newStock = max(0.0, round($stock - $kg, 3));
        $converter = OilDensityConverter::fromConfig();

        $tank->stock_kg = (string) $newStock;
        $tank->stock_l = (string) $converter->toLiters($newStock);
        $tank->lot_composition = $newStock <= 0.0005 ? [] : $split['remaining'];

        if ($newStock <= 0.0005) {
            $tank->stock_kg = '0';
            $tank->stock_l = '0';
            $tank->category = null;
            $tank->origin_designation = null;
        }

        $tank->save();

        return $split['removed'];
    }
}
