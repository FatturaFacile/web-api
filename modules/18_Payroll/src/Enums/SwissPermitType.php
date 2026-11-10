<?php

declare(strict_types=1);

namespace Modules\Payroll\Enums;

/**
 * Swiss work and residence permit categories relevant to payroll and withholding tax (Quellensteuer).
 */
enum SwissPermitType: string
{
    /** Dimora (Aufenthaltsbewilligung) — subject to QST when resident in CH. */
    case B = 'B';

    /** Domicilio (Niederlassungsbewilligung) — ordinary taxation when resident in CH. */
    case C = 'C';

    /** Frontaliere (Grenzgängerbewilligung) — cross-border commuter, subject to QST. */
    case G = 'G';

    /** Dimora temporanea (Kurzaufenthaltsbewilligung) — subject to QST. */
    case L = 'L';

    /** Permesso Ci con attività lucrativa (diplomatic family members) — subject to QST. */
    case Ci = 'Ci';

    /** Ammissione provvisoria (Vorläufig aufgenommene Ausländer) — subject to QST. */
    case F = 'F';

    /** Richiedente l'asilo (Asylsuchende) — subject to QST. */
    case N = 'N';

    /** Persone bisognose di protezione (Schutzbedürftige) — subject to QST. */
    case S = 'S';

    /** Cittadino svizzero (Schweizer Bürgerrecht) — ordinary taxation when resident in CH. */
    case SwissCitizen = 'CH';

    /** Procedura di notifica <= 90 giorni (Meldeverfahren) — subject to QST. */
    case Meldeverfahren = 'Meldeverfahren';

    public static function tryFromInsensitive(?string $value): ?self
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $normalized = trim($value);

        foreach (self::cases() as $case) {
            if (strcasecmp($case->value, $normalized) === 0) {
                return $case;
            }
        }

        return null;
    }

    public function requiresWithholdingTax(string $residenceCountry = 'CH'): bool
    {
        if (strtoupper($residenceCountry) !== 'CH') {
            return true;
        }

        return match ($this) {
            self::B, self::G, self::L, self::Ci, self::F, self::N, self::S, self::Meldeverfahren => true,
            self::C, self::SwissCitizen => false,
        };
    }

    public function requiresValidityDate(): bool
    {
        return match ($this) {
            self::B, self::G, self::L, self::Ci, self::F, self::N, self::S, self::Meldeverfahren => true,
            self::C, self::SwissCitizen => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::B => 'Permesso B (Dimora)',
            self::C => 'Permesso C (Domicilio)',
            self::G => 'Permesso G (Frontaliere)',
            self::L => 'Permesso L (Dimora temporanea)',
            self::Ci => 'Permesso Ci (Attività lucrativa familiari diplomatici)',
            self::F => 'Permesso F (Ammissione provvisoria)',
            self::N => 'Permesso N (Richiedente asilo)',
            self::S => 'Permesso S (Protezione provvisoria)',
            self::SwissCitizen => 'Cittadinanza svizzera (CH)',
            self::Meldeverfahren => 'Procedura di notifica (<= 90 giorni)',
        };
    }
}
