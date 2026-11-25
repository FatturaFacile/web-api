<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Application\Queries;

final readonly class SimulateScaleQuery
{
    /**
     * @param  array<int|string, mixed>  $tiers
     * @param  array<int|string, mixed>  $volumes
     */
    public function __construct(
        public string $tierMode,
        public string $volumeMode,
        public string $baseline,
        public array $tiers,
        public array $volumes,
    ) {}
}
