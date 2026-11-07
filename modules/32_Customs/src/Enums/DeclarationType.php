<?php

declare(strict_types=1);

namespace Modules\Customs\Enums;

enum DeclarationType: string
{
    case Import = 'import';
    case Export = 'export';
    case Transit = 'transit';

    /**
     * The serviceType of the e-dec schema v4.0. Transit has none: e-dec only carries import and export.
     */
    public function edecServiceType(): ?int
    {
        return match ($this) {
            self::Import => 1,
            self::Export => 2,
            self::Transit => null,
        };
    }
}
