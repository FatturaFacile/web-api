<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Domain\Pricing;

/**
 * The delivered quantity and the quality of a breeder in a settlement month.
 */
final readonly class PricingInput
{
    public function __construct(
        public float $totalKg,
        public float $cheeseMilkKg,
        public ?float $fatPct,
        public ?float $proteinPct,
        public ?float $caseinPct,
        public ?float $ureaMgL,
        public ?float $tbcCfuMl,
        public ?float $sccCellsMl,
        public ?float $freezingPointC,
        public bool $inhibitorPositive,
    ) {}

    public function valueOf(string $component): ?float
    {
        return match ($component) {
            'fat' => $this->fatPct,
            'protein' => $this->proteinPct,
            'casein' => $this->caseinPct,
            'urea' => $this->ureaMgL,
            'tbc' => $this->tbcCfuMl,
            'scc' => $this->sccCellsMl,
            'freezing_point' => $this->freezingPointC,
            'inhibitor' => $this->inhibitorPositive ? 1.0 : 0.0,
            default => null,
        };
    }
}
