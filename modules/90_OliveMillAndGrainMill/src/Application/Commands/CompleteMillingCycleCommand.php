<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Application\Commands;

use Modules\OliveMillAndGrainMill\Domain\Enums\OriginDesignation;

/**
 * Closes a milling cycle: output, yield, lab values, by-products and the destination of the product.
 */
final readonly class CompleteMillingCycleCommand
{
    public function __construct(
        public string $cycleId,
        public ?string $endedAt,
        public ?float $outputKg,
        public ?float $outputLiters,
        public ?float $malaxationTemperatureC,
        public ?float $waterAddedL,
        public ?float $acidityPct,
        public ?float $peroxides,
        public ?float $polyphenolsMgKg,
        public ?string $panelNotes,
        public ?float $sansaKg,
        public ?float $nocciolinoKg,
        public ?float $vegetationWaterL,
        public ?float $branKg,
        public ?float $pickupKg,
        public ?float $molendaKg,
        public ?float $millStockKg,
        public ?string $targetTankId,
        public ?OriginDesignation $originDesignation,
        public ?string $userId,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated request data
     */
    public static function fromArray(array $data, ?string $userId = null): self
    {
        return new self(
            cycleId: (string) $data['cycle_id'],
            endedAt: isset($data['ended_at']) ? (string) $data['ended_at'] : null,
            outputKg: isset($data['output_kg']) ? (float) $data['output_kg'] : null,
            outputLiters: isset($data['output_liters']) ? (float) $data['output_liters'] : null,
            malaxationTemperatureC: isset($data['malaxation_temperature_c']) ? (float) $data['malaxation_temperature_c'] : null,
            waterAddedL: isset($data['water_added_l']) ? (float) $data['water_added_l'] : null,
            acidityPct: isset($data['acidity_pct']) ? (float) $data['acidity_pct'] : null,
            peroxides: isset($data['peroxides']) ? (float) $data['peroxides'] : null,
            polyphenolsMgKg: isset($data['polyphenols_mg_kg']) ? (float) $data['polyphenols_mg_kg'] : null,
            panelNotes: isset($data['panel_notes']) ? (string) $data['panel_notes'] : null,
            sansaKg: isset($data['sansa_kg']) ? (float) $data['sansa_kg'] : null,
            nocciolinoKg: isset($data['nocciolino_kg']) ? (float) $data['nocciolino_kg'] : null,
            vegetationWaterL: isset($data['vegetation_water_l']) ? (float) $data['vegetation_water_l'] : null,
            branKg: isset($data['bran_kg']) ? (float) $data['bran_kg'] : null,
            pickupKg: isset($data['pickup_kg']) ? (float) $data['pickup_kg'] : null,
            molendaKg: isset($data['molenda_kg']) ? (float) $data['molenda_kg'] : null,
            millStockKg: isset($data['mill_stock_kg']) ? (float) $data['mill_stock_kg'] : null,
            targetTankId: isset($data['target_tank_id']) ? (string) $data['target_tank_id'] : null,
            originDesignation: isset($data['origin_designation']) ? OriginDesignation::from((string) $data['origin_designation']) : null,
            userId: $userId,
        );
    }
}
