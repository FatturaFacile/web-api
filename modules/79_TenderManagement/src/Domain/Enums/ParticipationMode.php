<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Domain\Enums;

enum ParticipationMode: string
{
    case Single = 'SingleCompany';
    case Rti = 'RTI_Consorzio_Mandataria_Mandante';
    case Avvalimento = 'Avvalimento';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
