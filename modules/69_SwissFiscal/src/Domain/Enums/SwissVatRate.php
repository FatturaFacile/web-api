<?php

declare(strict_types=1);

namespace Modules\SwissFiscal\Domain\Enums;

use Modules\SwissFiscal\Domain\ValueObjects\Money;

/** Swiss VAT rates in force since 1 January 2024. */
enum SwissVatRate: string
{
    case Standard = 'standard';
    case Reduced = 'reduced';
    case Accommodation = 'accommodation';
    case Exempt = 'exempt';

    /** Percentage as a decimal string. */
    public function percent(): string
    {
        return match ($this) {
            self::Standard => '8.1',
            self::Reduced => '2.6',
            self::Accommodation => '3.8',
            self::Exempt => '0',
        };
    }

    /** VAT due on a net amount. */
    public function onNet(Money $net): Money
    {
        return $net->percentage($this->percent());
    }

    /** VAT contained in a gross (VAT-inclusive) amount. */
    public function inGross(Money $gross): Money
    {
        $divisor = bcadd('100', $this->percent(), 4);

        return new Money(
            Money::roundHalfAwayFromZero(bcdiv(bcmul((string) $gross->minor, $this->percent(), 12), $divisor, 12)),
            $gross->currency,
        );
    }
}
