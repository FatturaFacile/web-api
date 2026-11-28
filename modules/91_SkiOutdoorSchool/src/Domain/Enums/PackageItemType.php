<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Domain\Enums;

/**
 * Add-on sold together with a lesson or a course: equipment rental, avalanche safety kit or skipass.
 */
enum PackageItemType: string
{
    case RENTAL_SKI_KIT = 'RENTAL_SKI_KIT';
    case RENTAL_SNOWBOARD_KIT = 'RENTAL_SNOWBOARD_KIT';
    case RENTAL_BOOTS = 'RENTAL_BOOTS';
    case RENTAL_HELMET = 'RENTAL_HELMET';
    case ARTVA_KIT = 'ARTVA_KIT';
    case SKIPASS = 'SKIPASS';
    case OTHER = 'OTHER';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /** Key of the VAT rate table of config/ski_school.php. */
    public function vatCategory(): string
    {
        return match ($this) {
            self::SKIPASS => 'skipass',
            self::OTHER => 'other',
            default => 'rental',
        };
    }

    /** Whether the item is physical equipment handed over and returned (inventory / rental hook). */
    public function isEquipment(): bool
    {
        return $this->vatCategory() === 'rental';
    }
}
