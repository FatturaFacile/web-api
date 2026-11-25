<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Application\Queries;

use Modules\GdoTradeTerms\Services\ScaleSimulator;

final class SimulateScaleHandler
{
    public function __construct(private readonly ScaleSimulator $simulator) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(SimulateScaleQuery $query): array
    {
        return $this->simulator->simulate($query->tierMode, $query->volumeMode, $query->baseline, $query->tiers, $query->volumes);
    }
}
