<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Domain\ValueObjects;

use Modules\OliveMillAndGrainMill\Domain\Exceptions\OliveMillException;

/**
 * One weighbridge reading: gross weight, the tare of the vehicle and of the crates, and the resulting net weight of the delivered crop.
 */
final readonly class WeighbridgeMeasurement
{
    public function __construct(
        public float $grossKg,
        public float $tareKg,
        public int $crateCount,
        public float $crateUnitTareKg,
    ) {}

    /**
     * Net = gross - vehicle tare - crates x unit tare.
     *
     * @throws OliveMillException weight_invalid, net_weight_not_positive
     */
    public function netKg(): float
    {
        if ($this->grossKg <= 0.0 || $this->tareKg < 0.0 || $this->crateCount < 0 || $this->crateUnitTareKg < 0.0) {
            throw OliveMillException::because('weight_invalid');
        }

        $net = round($this->grossKg - $this->tareKg - $this->crateCount * $this->crateUnitTareKg, 3);

        if ($net <= 0.0) {
            throw OliveMillException::because('net_weight_not_positive', ['net' => $net]);
        }

        return $net;
    }

    public function totalTareKg(): float
    {
        return round($this->tareKg + $this->crateCount * $this->crateUnitTareKg, 3);
    }
}
