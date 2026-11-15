<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services\Localization;

/**
 * Standard VAT codes of the countries with a localization pack. The codes are shared by every tenant (generic rows),
 * so each code carries the country prefix to stay unique. Entry: code, description, rate, reverse charge, exempt nature.
 */
final class VatCodePackCatalog
{
    /**
     * @return array<string, array{country: string, name: string, codes: list<array{code: string, description: string, rate: string, reverse_charge: bool, nature: string|null}>}>
     */
    public static function all(): array
    {
        return [
            'FR' => [
                'country' => 'FR',
                'name' => 'France: TVA',
                'codes' => [
                    ['code' => 'FR_TVA_20', 'description' => 'TVA taux normal 20%', 'rate' => '20.00', 'reverse_charge' => false, 'nature' => null],
                    ['code' => 'FR_TVA_10', 'description' => 'TVA taux intermediaire 10%', 'rate' => '10.00', 'reverse_charge' => false, 'nature' => null],
                    ['code' => 'FR_TVA_55', 'description' => 'TVA taux reduit 5.5%', 'rate' => '5.50', 'reverse_charge' => false, 'nature' => null],
                    ['code' => 'FR_TVA_21', 'description' => 'TVA taux particulier 2.1% (medicaments remboursables, presse)', 'rate' => '2.10', 'reverse_charge' => false, 'nature' => null],
                    ['code' => 'FR_TVA_EXO', 'description' => 'Operation exoneree de TVA', 'rate' => '0.00', 'reverse_charge' => false, 'nature' => 'EXO'],
                    ['code' => 'FR_TVA_AUTO', 'description' => 'Autoliquidation de la TVA (acquisition intracommunautaire, sous-traitance)', 'rate' => '0.00', 'reverse_charge' => true, 'nature' => 'AUTO'],
                    ['code' => 'FR_TVA_EXPORT', 'description' => 'Exportation hors UE (exoneration)', 'rate' => '0.00', 'reverse_charge' => false, 'nature' => 'EXP'],
                ],
            ],
            'ES' => [
                'country' => 'ES',
                'name' => 'Spain: IVA',
                'codes' => [
                    ['code' => 'ES_IVA_21', 'description' => 'IVA tipo general 21%', 'rate' => '21.00', 'reverse_charge' => false, 'nature' => null],
                    ['code' => 'ES_IVA_10', 'description' => 'IVA tipo reducido 10%', 'rate' => '10.00', 'reverse_charge' => false, 'nature' => null],
                    ['code' => 'ES_IVA_4', 'description' => 'IVA tipo superreducido 4%', 'rate' => '4.00', 'reverse_charge' => false, 'nature' => null],
                    ['code' => 'ES_IVA_EXENTO', 'description' => 'Operacion exenta de IVA', 'rate' => '0.00', 'reverse_charge' => false, 'nature' => 'E1'],
                    ['code' => 'ES_IVA_ISP', 'description' => 'Inversion del sujeto pasivo', 'rate' => '0.00', 'reverse_charge' => true, 'nature' => 'S2'],
                    ['code' => 'ES_IVA_EXPORT', 'description' => 'Exportacion (exenta, articulo 21)', 'rate' => '0.00', 'reverse_charge' => false, 'nature' => 'E2'],
                    ['code' => 'ES_IVA_NOSUJETO', 'description' => 'Operacion no sujeta a IVA', 'rate' => '0.00', 'reverse_charge' => false, 'nature' => 'NS'],
                ],
            ],
        ];
    }
}
