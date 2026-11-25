<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Domain\Enums;

enum PromotionType: string
{
    case SellInFlyer = 'sell_in_flyer';
    case SellOut = 'sell_out';
    case NewStoreOpening = 'new_store_opening';
    case ListingFee = 'listing_fee';
    case Display = 'display';
    case CediLogistics = 'cedi_logistics';

    /**
     * Fiscal treatment of the contribution: a flyer price reduction is a consideration reduction settled with a credit
     * note; listing, display, opening, logistics and sell-out contributions pay a service of the retailer, which invoices it.
     */
    public function treatment(): Treatment
    {
        return $this === self::SellInFlyer ? Treatment::CreditNote : Treatment::PromoInvoice;
    }

    /**
     * Whether the contribution reads the sell-out reported by the retailer.
     */
    public function readsSellOut(): bool
    {
        return $this === self::SellOut;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
