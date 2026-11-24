<?php

declare(strict_types=1);

namespace App\Enums\Privacy;

enum BreachJurisdiction: string
{
    case ItGdprGarante = 'it_gdpr_garante';
    case ChNlpdFdpic = 'ch_nlpd_fdpic';
    case BothChIt = 'both_ch_it';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
