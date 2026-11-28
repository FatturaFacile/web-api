<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Domain\Services;

use Modules\OliveMillAndGrainMill\Domain\Enums\Certification;
use Modules\OliveMillAndGrainMill\Domain\Enums\OilCategory;
use Modules\OliveMillAndGrainMill\Domain\ValueObjects\AcidityProfile;
use Modules\OliveMillAndGrainMill\Domain\ValueObjects\OilDensityConverter;
use Modules\OliveMillAndGrainMill\Domain\ValueObjects\WeighbridgeMeasurement;
use Modules\OliveMillAndGrainMill\Domain\ValueObjects\YieldPercentage;

/**
 * Net weights, oil / flour yield, cold extraction eligibility and quality grading of a milling cycle.
 */
final class YieldCalculationService
{
    /**
     * @param  array<string, array{acidity_max: float, peroxides_max: float}>  $thresholds
     */
    public function __construct(
        private readonly OilDensityConverter $density,
        private readonly float $coldExtractionMaxC,
        private readonly array $thresholds,
    ) {}

    public static function fromConfig(): self
    {
        /** @var array<string, array{acidity_max: float, peroxides_max: float}> $thresholds */
        $thresholds = (array) config('olive_mill.thresholds');

        return new self(OilDensityConverter::fromConfig(), (float) config('olive_mill.cold_extraction_max_c', 27.0), $thresholds);
    }

    public function netWeight(WeighbridgeMeasurement $measurement): float
    {
        return $measurement->netKg();
    }

    /**
     * Yield, volume, cold extraction flag and quality class of one cycle.
     *
     * @return array{yield_percent: float, output_liters: float, cold_extraction: bool, quality_class: ?string, warnings: list<string>}
     */
    public function evaluate(float $inputKg, float $outputKg, ?float $temperatureC, ?AcidityProfile $lab, ?Certification $declared, bool $isOil): array
    {
        $warnings = [];
        $class = null;

        if ($isOil && $lab !== null) {
            $class = $lab->qualityClass($this->thresholds);

            if ($declared !== null && ! $lab->satisfies($declared->oilCategory(), $this->thresholds)) {
                $warnings[] = 'quality_below_declared';
            }

            if ($declared === Certification::Evo && $lab->freeAcidityPct !== null && $lab->freeAcidityPct > $this->thresholds['EVO']['acidity_max']) {
                $warnings[] = 'evo_acidity_exceeded';
            }
        }

        return [
            'yield_percent' => YieldPercentage::of($outputKg, $inputKg)->value,
            'output_liters' => $isOil ? $this->density->toLiters($outputKg) : 0.0,
            'cold_extraction' => $isOil && $temperatureC !== null && $temperatureC <= $this->coldExtractionMaxC,
            'quality_class' => $class?->value,
            'warnings' => $warnings,
        ];
    }

    public function liters(float $kg): float
    {
        return $this->density->toLiters($kg);
    }

    public function kilograms(float $liters): float
    {
        return $this->density->toKg($liters);
    }

    /**
     * @return array{acidity_max: float, peroxides_max: float}
     */
    public function limits(OilCategory $category): array
    {
        return $this->thresholds[$category->grade() >= 3 ? 'EVO' : 'VERGINE'];
    }
}
