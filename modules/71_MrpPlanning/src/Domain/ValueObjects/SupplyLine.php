<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Domain\ValueObjects;

use Carbon\CarbonImmutable;
use Modules\MrpPlanning\Domain\Enums\SupplyType;

/** A scheduled receipt: open purchase order (Mod 044/046), open production order (Mod 064) or stock in transit. */
final readonly class SupplyLine
{
    public function __construct(
        public SupplyType $type,
        public string $reference,
        public string $itemId,
        public CarbonImmutable $date,
        public string $quantity,
    ) {}
}
