<?php

declare(strict_types=1);

namespace App\Support\Portal;

/**
 * Public tracking page of a carrier, built from the carrier name written on the delivery note and its tracking number.
 * Unknown carriers return null: the customer then only sees the number.
 */
final class CarrierTrackingUrl
{
    /** @var array<string, string> lowercase carrier keyword => URL with a {number} placeholder */
    private const array TEMPLATES = [
        'dhl' => 'https://www.dhl.com/global-en/home/tracking.html?tracking-id={number}',
        'ups' => 'https://www.ups.com/track?tracknum={number}',
        'fedex' => 'https://www.fedex.com/fedextrack/?trknbr={number}',
        'tnt' => 'https://www.tnt.com/express/en_gb/site/shipping-tools/tracking.html?searchType=con&cons={number}',
        'gls' => 'https://gls-group.com/IT/it/ricerca-spedizioni?match={number}',
        'brt' => 'https://www.brt.it/it/tracking-e-spedizioni?parcelNumberFrom={number}',
        'bartolini' => 'https://www.brt.it/it/tracking-e-spedizioni?parcelNumberFrom={number}',
        'dpd' => 'https://tracking.dpd.de/parcelstatus?query={number}',
        'swiss post' => 'https://service.post.ch/ekp-web/external/parcel/track?formattedParcelCodes={number}',
        'posta svizzera' => 'https://service.post.ch/ekp-web/external/parcel/track?formattedParcelCodes={number}',
        'die post' => 'https://service.post.ch/ekp-web/external/parcel/track?formattedParcelCodes={number}',
    ];

    public static function for(?string $carrier, ?string $trackingNumber): ?string
    {
        $number = trim((string) $trackingNumber);

        if ($carrier === null || $number === '') {
            return null;
        }

        $name = mb_strtolower($carrier);

        foreach (self::TEMPLATES as $keyword => $template) {
            if (str_contains($name, $keyword)) {
                return str_replace('{number}', rawurlencode($number), $template);
            }
        }

        return null;
    }
}
