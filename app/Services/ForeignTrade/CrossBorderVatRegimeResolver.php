<?php

declare(strict_types=1);

namespace App\Services\ForeignTrade;

/**
 * Resolves the VAT treatment of a cross-border supply of goods from the seller (tenant) and counterparty countries.
 */
final class CrossBorderVatRegimeResolver
{
    public const DOMESTIC = 'domestic';

    public const CH_EXPORT_EXEMPT = 'ch_export_art_23_ltva';

    public const CH_IMPORT_TAX = 'ch_import_tax_zaz';

    public const IT_INTRA_EU_SUPPLY = 'it_intra_eu_art_41_dl_331_93';

    public const INTRA_EU_REVERSE_CHARGE = 'intra_eu_reverse_charge';

    public const IT_EXPORT = 'it_export_art_8_dpr_633_72';

    public const IMPORT_CUSTOMS_VAT = 'import_customs_vat';

    public const EXPORT_EXEMPT = 'export_exempt';

    private const EU = ['AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE'];

    /**
     * @return array{regime: string, vat_exempt: bool, reverse_charge: bool, requires_vies: bool, requires_intrastat: bool, requires_customs_declaration: bool}
     */
    public function resolve(string $tenantCountry, string $counterpartyCountry, bool $counterpartyIsVatTaxable = true, bool $isPurchase = false): array
    {
        $tenant = strtoupper($tenantCountry);
        $other = strtoupper($counterpartyCountry);

        if ($tenant === '' || $other === '' || $tenant === $other) {
            return $this->result(self::DOMESTIC);
        }

        $tenantEu = $this->isEu($tenant);
        $otherEu = $this->isEu($other);

        if ($tenantEu && $otherEu) {
            if ($isPurchase) {
                return $this->result(self::INTRA_EU_REVERSE_CHARGE, reverse: true, vies: true, intrastat: true);
            }

            // A supply to a non-taxable EU buyer is not an intra-EU supply: the domestic VAT applies.
            return $counterpartyIsVatTaxable
                ? $this->result(self::IT_INTRA_EU_SUPPLY, exempt: true, vies: true, intrastat: true)
                : $this->result(self::DOMESTIC);
        }

        if ($isPurchase) {
            return $this->result($tenant === 'CH' ? self::CH_IMPORT_TAX : self::IMPORT_CUSTOMS_VAT, customs: true);
        }

        if ($tenant === 'CH') {
            return $this->result(self::CH_EXPORT_EXEMPT, exempt: true, customs: true);
        }

        return $tenantEu
            ? $this->result(self::IT_EXPORT, exempt: true, customs: true)
            : $this->result(self::EXPORT_EXEMPT, exempt: true, customs: true);
    }

    public function isEu(string $country): bool
    {
        return in_array(strtoupper($country), self::EU, true);
    }

    /**
     * @return array{regime: string, vat_exempt: bool, reverse_charge: bool, requires_vies: bool, requires_intrastat: bool, requires_customs_declaration: bool}
     */
    private function result(string $regime, bool $exempt = false, bool $reverse = false, bool $vies = false, bool $intrastat = false, bool $customs = false): array
    {
        return [
            'regime' => $regime,
            'vat_exempt' => $exempt,
            'reverse_charge' => $reverse,
            'requires_vies' => $vies,
            'requires_intrastat' => $intrastat,
            'requires_customs_declaration' => $customs,
        ];
    }
}
