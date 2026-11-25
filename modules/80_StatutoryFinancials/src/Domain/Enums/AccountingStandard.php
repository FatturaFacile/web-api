<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Domain\Enums;

/**
 * The statutory reporting framework of a dossier. The Italian civil-code schemes (Art. 2424-2425, 2435-bis, 2435-ter
 * c.c., OIC) and the Swiss ones (CO Art. 957-963b, Swiss GAAP FER 1-31).
 */
enum AccountingStandard: string
{
    case ItOrdinario = 'IT_OIC_Ordinario';
    case ItAbbreviato = 'IT_OIC_Abbreviato';
    case ItMicro = 'IT_OIC_Micro';
    case ChCoArt959 = 'CH_CO_Art959';
    case ChSwissGaapFer = 'CH_SwissGaapFer';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public function jurisdiction(): string
    {
        return match ($this) {
            self::ItOrdinario, self::ItAbbreviato, self::ItMicro => 'IT',
            self::ChCoArt959, self::ChSwissGaapFer => 'CH',
        };
    }

    /**
     * The scheme of the line catalogue: "ord", "abb" or "micro" for Italy; Switzerland has one scheme ("ord").
     */
    public function scheme(): string
    {
        return match ($this) {
            self::ItAbbreviato => 'abb',
            self::ItMicro => 'micro',
            default => 'ord',
        };
    }

    public function legalReference(): string
    {
        return match ($this) {
            self::ItOrdinario => 'Art. 2424-2425 c.c.',
            self::ItAbbreviato => 'Art. 2435-bis c.c.',
            self::ItMicro => 'Art. 2435-ter c.c.',
            self::ChCoArt959 => 'CO Art. 957-963b',
            self::ChSwissGaapFer => 'Swiss GAAP FER 1-31',
        };
    }
}
