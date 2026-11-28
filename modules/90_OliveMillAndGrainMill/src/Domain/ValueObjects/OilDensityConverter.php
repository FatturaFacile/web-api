<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Domain\ValueObjects;

use Modules\OliveMillAndGrainMill\Domain\Exceptions\OliveMillException;

/**
 * Converts olive oil between kilograms and litres with a configurable density (0.916 kg/L by default).
 */
final readonly class OilDensityConverter
{
    public function __construct(public float $density = 0.916)
    {
        if ($density <= 0.0) {
            throw OliveMillException::because('density_invalid');
        }
    }

    public static function fromConfig(): self
    {
        return new self((float) config('olive_mill.density_kg_per_l', 0.916));
    }

    public function toLiters(float $kg): float
    {
        return round($kg / $this->density, 3);
    }

    public function toKg(float $liters): float
    {
        return round($liters * $this->density, 3);
    }
}
