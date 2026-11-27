<?php

declare(strict_types=1);

namespace Modules\GreenCare\Domain\Enums;

/**
 * Phytosanitary operator certification: PER, PNRP and OPF in Switzerland (OPF / ORRChim), the PAN patentino in Italy (D.Lgs. 150/2012).
 */
enum LicenseType: string
{
    case Per = 'PER';
    case Pnrp = 'PNRP';
    case Opf = 'OPF';
    case Pan = 'PAN';

    public function jurisdiction(): string
    {
        return $this === self::Pan ? 'IT' : 'CH';
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
