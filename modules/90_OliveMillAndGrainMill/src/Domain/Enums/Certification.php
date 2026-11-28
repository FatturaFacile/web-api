<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Domain\Enums;

enum Certification: string
{
    case Evo = 'EVO';
    case Vergine = 'VERGINE';
    case Lampante = 'LAMPANTE';
    case Dop = 'DOP';
    case Igp = 'IGP';
    case Biologico = 'BIOLOGICO';
    case Convenzionale = 'CONVENZIONALE';

    /**
     * The category of the oil stored in a tank for this declared certification (conventional oil is extra virgin until the lab says otherwise).
     */
    public function oilCategory(): OilCategory
    {
        return match ($this) {
            self::Evo, self::Convenzionale => OilCategory::Evo,
            self::Vergine => OilCategory::Vergine,
            self::Lampante => OilCategory::Lampante,
            self::Dop => OilCategory::Dop,
            self::Igp => OilCategory::Igp,
            self::Biologico => OilCategory::Bio,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
