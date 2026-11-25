<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Domain\Enums;

enum TaxTreatment: string
{
    case ItArt26Td04 = 'it_art26_td04';
    case ChArt41ConsiderationReduction = 'ch_art41_consideration_reduction';
    case StandardVat = 'standard_vat';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
