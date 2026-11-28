<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Domain\Enums;

enum OilCategory: string
{
    case Evo = 'EVO';
    case Vergine = 'VERGINE';
    case Lampante = 'LAMPANTE';
    case Dop = 'DOP';
    case Igp = 'IGP';
    case Bio = 'BIO';

    /**
     * Quality grade of the oil: 3 extra virgin (DOP, IGP and organic oils are extra virgin), 2 virgin, 1 lampante.
     */
    public function grade(): int
    {
        return match ($this) {
            self::Evo, self::Dop, self::Igp, self::Bio => 3,
            self::Vergine => 2,
            self::Lampante => 1,
        };
    }

    /**
     * The plain quality class of a grade.
     */
    public static function ofGrade(int $grade): self
    {
        return match (true) {
            $grade >= 3 => self::Evo,
            $grade === 2 => self::Vergine,
            default => self::Lampante,
        };
    }

    /**
     * Protected designation or organic oil: it cannot be blended with any other category.
     */
    public function isSegregated(): bool
    {
        return in_array($this, [self::Dop, self::Igp, self::Bio], true);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
