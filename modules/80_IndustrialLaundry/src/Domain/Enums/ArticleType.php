<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Domain\Enums;

/**
 * What a tagged textile is. Personal workwear and PPE are worn by one employee, so they are the ones assigned to a locker.
 */
enum ArticleType: string
{
    case FlatLinen = 'flat_linen';
    case TerryTowel = 'terry_towel';
    case HospitalScrub = 'hospital_scrub';
    case PersonalWorkwearUniform = 'personal_workwear_uniform';
    case PpeSafetyGarment = 'ppe_safety_garment';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public function isNominal(): bool
    {
        return $this === self::PersonalWorkwearUniform || $this === self::PpeSafetyGarment;
    }

    public function isPpe(): bool
    {
        return $this === self::PpeSafetyGarment;
    }
}
