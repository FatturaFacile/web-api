<?php

declare(strict_types=1);

namespace Modules\ProductCatalog\Domain\Enums;

/**
 * The kind of item: storable goods are stocked and valued, consumables are issued without tracking value, services and digital
 * goods never move stock.
 */
enum ProductType: string
{
    use EnumValues;

    case Storable = 'storable';
    case Consumable = 'consumable';
    case Service = 'service';
    case Digital = 'digital';

    public function tracksStock(): bool
    {
        return in_array($this, [self::Storable, self::Consumable], true);
    }

    public function canBeKitComponent(): bool
    {
        return $this !== self::Digital;
    }
}
