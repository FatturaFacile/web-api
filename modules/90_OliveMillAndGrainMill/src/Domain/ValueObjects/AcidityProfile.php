<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Domain\ValueObjects;

use Modules\OliveMillAndGrainMill\Domain\Enums\OilCategory;

/**
 * Lab parameters of an oil batch and the quality class they allow under the EU / MASAF thresholds.
 */
final readonly class AcidityProfile
{
    public function __construct(
        public ?float $freeAcidityPct,
        public ?float $peroxides,
        public ?float $polyphenolsMgKg = null,
    ) {}

    /**
     * Extra virgin, virgin or lampante from the free acidity and the peroxide number; null when the acidity was not measured.
     *
     * @param  array<string, array{acidity_max: float, peroxides_max: float}>  $thresholds
     */
    public function qualityClass(array $thresholds): ?OilCategory
    {
        if ($this->freeAcidityPct === null) {
            return null;
        }

        foreach ([[OilCategory::Evo, 'EVO'], [OilCategory::Vergine, 'VERGINE']] as [$category, $key]) {
            $limit = $thresholds[$key];

            if ($this->freeAcidityPct <= $limit['acidity_max'] && ($this->peroxides === null || $this->peroxides <= $limit['peroxides_max'])) {
                return $category;
            }
        }

        return OilCategory::Lampante;
    }

    /**
     * Whether the measured class is good enough for the declared category (a declared DOP, IGP or organic oil must be at least extra virgin).
     *
     * @param  array<string, array{acidity_max: float, peroxides_max: float}>  $thresholds
     */
    public function satisfies(OilCategory $declared, array $thresholds): bool
    {
        $class = $this->qualityClass($thresholds);

        return $class === null || $class->grade() >= $declared->grade();
    }
}
