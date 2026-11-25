<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Enums;

/**
 * The jurisdictions a provider configuration is certified for.
 */
enum ProviderJurisdiction: string
{
    case Ch = 'CH';
    case Eu = 'EU';
    case Both = 'BOTH';

    public function covers(Jurisdiction $jurisdiction): bool
    {
        return match ($this) {
            self::Both => true,
            self::Ch => $jurisdiction === Jurisdiction::Zertes,
            self::Eu => $jurisdiction === Jurisdiction::Eidas,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $jurisdiction): string => $jurisdiction->value, self::cases());
    }
}
