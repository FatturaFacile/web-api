<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Domain\ValueObjects;

use Modules\OliveMillAndGrainMill\Domain\Exceptions\OliveMillException;

/**
 * Yield of a milling cycle: extracted oil (or flour) over the net weight of the crop, in percent with two decimals.
 */
final readonly class YieldPercentage
{
    private function __construct(public float $value) {}

    /**
     * @throws OliveMillException input_weight_not_positive, output_negative
     */
    public static function of(float $outputKg, float $inputKg): self
    {
        if ($inputKg <= 0.0) {
            throw OliveMillException::because('input_weight_not_positive');
        }

        if ($outputKg < 0.0) {
            throw OliveMillException::because('output_negative');
        }

        return new self(round($outputKg / $inputKg * 100, 2));
    }
}
