<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Enums;

/**
 * The three tiers of electronic signature of eIDAS (Regulation (EU) 910/2014, art. 3) and of ZertES (SR 943.03, art. 2):
 * simple (FES / SES), advanced (FEA / AES) and qualified (FEQ / QES). A qualified signature is the only one that has the
 * effect of a handwritten signature (eIDAS art. 25(2), Swiss CO art. 14(2bis)).
 */
enum SignatureLevel: string
{
    case Fes = 'FES';
    case Fea = 'FEA';
    case Feq = 'FEQ';

    /**
     * Strength of the tier: a higher tier satisfies a requirement of a lower one.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Fes => 1,
            self::Fea => 2,
            self::Feq => 3,
        };
    }

    public function satisfies(self $required): bool
    {
        return $this->rank() >= $required->rank();
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $level): string => $level->value, self::cases());
    }
}
