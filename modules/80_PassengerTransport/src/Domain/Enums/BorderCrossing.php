<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Domain\Enums;

use Modules\PassengerTransport\Domain\Enums\Concerns\EnumValues;

/**
 * The road border crossings between Italy and Switzerland used by passenger transfers (Insubric and Alpine passes).
 */
enum BorderCrossing: string
{
    use EnumValues;

    case ChiassoBrogeda = 'chiasso_brogeda';
    case Gaggiolo = 'gaggiolo';
    case PonteTresa = 'ponte_tresa';
    case PortoCeresio = 'porto_ceresio';
    case Sempione = 'sempione';
    case GranSanBernardo = 'gran_san_bernardo';
    case Castasegna = 'castasegna';
    case Tirano = 'tirano';
    case None = 'none';

    /**
     * @return array{lat: float, lng: float}|null null for "none"
     */
    public function coordinates(): ?array
    {
        return match ($this) {
            self::ChiassoBrogeda => ['lat' => 45.8296, 'lng' => 9.0316],
            self::Gaggiolo => ['lat' => 45.8456, 'lng' => 8.9330],
            self::PonteTresa => ['lat' => 45.9670, 'lng' => 8.8590],
            self::PortoCeresio => ['lat' => 45.9040, 'lng' => 8.9190],
            self::Sempione => ['lat' => 46.1960, 'lng' => 8.1480],
            self::GranSanBernardo => ['lat' => 45.8690, 'lng' => 7.1710],
            self::Castasegna => ['lat' => 46.3300, 'lng' => 9.5050],
            self::Tirano => ['lat' => 46.2310, 'lng' => 10.1360],
            self::None => null,
        };
    }

    /**
     * Crossings that can be picked automatically; "none" is excluded.
     *
     * @return list<self>
     */
    public static function crossings(): array
    {
        return array_filter(self::cases(), static fn (self $case): bool => $case !== self::None);
    }
}
