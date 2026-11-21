<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Domain\ValueObjects;

/**
 * One component of a parent item: quantity per parent unit (yield already applied, phantoms already collapsed) and the
 * scrap percentage that is added on top of it when the requirement is exploded.
 */
final readonly class BomLine
{
    public function __construct(
        public string $childItemId,
        public string $quantityPer,
        public string $scrapPct = '0',
    ) {}
}
