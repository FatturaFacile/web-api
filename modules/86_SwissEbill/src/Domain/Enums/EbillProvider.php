<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Domain\Enums;

enum EbillProvider: string
{
    case SixPaynetDirect = 'six_paynet_direct';
    case PostfinanceEbill = 'postfinance_ebill';
    case SandboxSimulator = 'sandbox_simulator';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public function isSandbox(): bool
    {
        return $this === self::SandboxSimulator;
    }
}
