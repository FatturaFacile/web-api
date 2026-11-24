<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Domain\ValueObjects;

use Carbon\CarbonImmutable;
use Modules\MrpPlanning\Domain\Enums\DemandSourceType;

/** Independent or firm dependent demand: an MPS line (Mod 062), a sales order line (Mod 037), a production order's components. */
final readonly class DemandLine
{
    public function __construct(
        public DemandSourceType $sourceType,
        public string $sourceId,
        public string $itemId,
        public CarbonImmutable $date,
        public string $quantity,
    ) {}
}
