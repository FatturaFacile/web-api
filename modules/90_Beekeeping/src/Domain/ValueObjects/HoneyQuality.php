<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Domain\ValueObjects;

/**
 * Moisture (refractometer, %) and HMF (mg/kg) of a honey batch with the status against the statutory ceilings.
 * Status: `ok`, `warning` (above the voluntary quality limit or approaching the HMF ceiling), `critical` (above the statutory limit), `unknown`.
 */
final readonly class HoneyQuality
{
    public function __construct(
        public ?float $moisturePct,
        public ?float $hmfMgKg,
        private float $moistureWarning = 18.0,
        private float $moistureLimit = 20.0,
        private float $hmfWarning = 30.0,
        private float $hmfLimit = 40.0,
    ) {}

    public static function fromConfig(?float $moisturePct, ?float $hmfMgKg): self
    {
        return new self(
            $moisturePct,
            $hmfMgKg,
            (float) config('beekeeping.quality.moisture_warning'),
            (float) config('beekeeping.quality.moisture_limit'),
            (float) config('beekeeping.quality.hmf_warning'),
            (float) config('beekeeping.quality.hmf_limit'),
        );
    }

    public function moistureStatus(): string
    {
        return match (true) {
            $this->moisturePct === null => 'unknown',
            $this->moisturePct > $this->moistureLimit => 'critical',
            $this->moisturePct > $this->moistureWarning => 'warning',
            default => 'ok',
        };
    }

    public function hmfStatus(): string
    {
        return match (true) {
            $this->hmfMgKg === null => 'unknown',
            $this->hmfMgKg > $this->hmfLimit => 'critical',
            $this->hmfMgKg >= $this->hmfWarning => 'warning',
            default => 'ok',
        };
    }

    /**
     * Whether the honey may be sold as honey: no parameter above its statutory ceiling.
     */
    public function isMarketable(): bool
    {
        return $this->moistureStatus() !== 'critical' && $this->hmfStatus() !== 'critical';
    }

    /**
     * @return list<string> codes of the warnings (frontend key beekeeping.warning.<code>)
     */
    public function warnings(): array
    {
        $codes = [];

        foreach (['moisture' => $this->moistureStatus(), 'hmf' => $this->hmfStatus()] as $parameter => $status) {
            if ($status === 'warning' || $status === 'critical') {
                $codes[] = "{$parameter}_{$status}";
            }
        }

        return $codes;
    }
}
