<?php

declare(strict_types=1);

namespace App\Enums\Privacy;

enum BreachRisk: string
{
    case Unlikely = 'unlikely';
    case Possible = 'possible';
    case Likely = 'likely';
    case HighRisk = 'high_risk';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
