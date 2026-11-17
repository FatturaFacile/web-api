<?php

declare(strict_types=1);

namespace Modules\SDI\Services;

use App\Models\PassiveInvoice;
use Modules\SDI\Enums\TipoDocumento;

/**
 * Decides which integration self-invoice a received supplier invoice needs, or null when none is due:
 * TD16 domestic reverse charge (Italian supplier, nature N6.x), TD17 services from abroad, TD18 intra-EU goods,
 * TD19 goods of a non-EU supplier already in Italy (art. 17 c. 2 DPR 633/72).
 */
final class IntegrationDocumentTypeDetector
{
    public const array EU_COUNTRIES = [
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT',
        'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE',
    ];

    /**
     * @param  ?string  $supplyKind  goods or services; needed for a foreign supplier, the invoice does not tell them apart
     */
    public function detect(PassiveInvoice $invoice, ?string $supplyKind): ?TipoDocumento
    {
        $country = strtoupper((string) data_get($invoice, 'supplier.country', 'IT'));

        if ($country === 'IT') {
            $reverseCharge = $invoice->lines()->where('vat_nature', 'like', 'N6%')->exists();

            return $reverseCharge ? TipoDocumento::Td16 : null;
        }

        $eu = in_array($country, self::EU_COUNTRIES, true);

        return match ($supplyKind) {
            'services' => TipoDocumento::Td17,
            'goods' => $eu ? TipoDocumento::Td18 : TipoDocumento::Td19,
            default => null,
        };
    }

    /**
     * Whether the supplier invoice is a candidate for an integration (foreign supplier or reverse-charge nature).
     */
    public function isCandidate(PassiveInvoice $invoice): bool
    {
        $country = strtoupper((string) data_get($invoice, 'supplier.country', 'IT'));

        return $country !== 'IT' || $invoice->lines()->where('vat_nature', 'like', 'N6%')->exists();
    }
}
