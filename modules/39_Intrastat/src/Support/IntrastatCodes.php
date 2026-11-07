<?php

declare(strict_types=1);

namespace Modules\Intrastat\Support;

/**
 * Code lists of the INTRA lists, copied from the official instructions. Nothing here is derived or completed by hand.
 *
 * Source: Agenzia delle Dogane e dei Monopoli, determinazione prot. n. 493869/RU del 23 dicembre 2021, Allegato XI
 * "Istruzioni per l'uso e la compilazione degli elenchi riepilogativi delle cessioni e degli acquisti intracomunitari di
 * beni e dei servizi resi e ricevuti" (periods from January 2022), confirmed in force by the determinazione prot. n.
 * 84415 del 3 febbraio 2026, art. 2 and art. 3:
 * https://www.adm.gov.it/portale/documents/20182/23456462/Allegato+11+Istruzioni+per+l%27uso+e+la+compilazione+degli+elenchi+riepilogativi.pdf/e7887bad-97cc-97bc-4761-6e37b1ec5b39?t=1640615553784
 *
 * - Tabella A: member states and number of characters of the VAT code (VIES). GB is left out: Allegato XI allows it only
 *   for periods before 2021, and this module handles periods from 2022. XI (Northern Ireland) is for goods only.
 * - Tabella B: natura della transazione, columns A and B, and the letters of triangular operations.
 * - Tabella C: modo di trasporto.
 * - Tabella D: condizioni di consegna; the lists carry the group code (third column), not the Incoterm.
 * - Modalità di erogazione (I, R) and modalità di incasso (B, A, X): Titolo II, Sezione 3, col. 8 and 9.
 * - Contenuto dell'elenco (0, 8, 9) and casi particolari (7, 8, 9, 0): Allegato XII, frontespizio, fields 11 and 12.
 * - Codice unico 99500000 for shipments under 1,000 euro: determinazione 493869/RU, art. 3, and Allegato XI.
 */
final class IntrastatCodes
{
    /**
     * Tabella A: member state code => allowed numbers of characters of the VAT code.
     *
     * @var array<string, list<int>>
     */
    public const array MemberStateVatLengths = [
        'AT' => [9],
        'BE' => [10],
        'BG' => [9, 10],
        'CY' => [9],
        'HR' => [11],
        'DK' => [8],
        'EE' => [9],
        'FI' => [8],
        'FR' => [11],
        'DE' => [9],
        'EL' => [9],
        'IE' => [8],
        'XI' => [5, 9, 12],
        'IT' => [11],
        'LV' => [11],
        'LT' => [9, 12],
        'LU' => [8],
        'MT' => [8],
        'NL' => [12],
        'PL' => [10],
        'PT' => [9],
        'CZ' => [8, 9, 10],
        'SK' => [10],
        'RO' => [2, 3, 4, 5, 6, 7, 8, 9, 10],
        'SI' => [8],
        'ES' => [9],
        'SE' => [12],
        'HU' => [8],
    ];

    /**
     * Tabella A, note (2): Northern Ireland is a partner for goods only; services with it are excluded.
     */
    public const string NorthernIreland = 'XI';

    public const string Italy = 'IT';

    /**
     * Tabella A uses EL for Greece, where ISO 3166 (and the contacts of the CRM) use GR.
     */
    public const array IsoToIntrastatCountry = ['GR' => 'EL'];

    /**
     * Tabella B: column A code => allowed column B codes. Code 8 has no column B code in the table.
     *
     * @var array<string, list<string>>
     */
    public const array TransactionNatures = [
        '1' => ['1', '2'],
        '2' => ['1', '2', '3'],
        '3' => ['1', '2', '3', '4'],
        '4' => ['1', '2'],
        '5' => ['1', '2'],
        '7' => ['1', '2'],
        '8' => [],
        '9' => ['1', '9'],
    ];

    /**
     * Tabella B, note N2: the pair 1/2 (direct trade with private consumers) is not reported in the INTRA lists.
     *
     * @var list<string>
     */
    public const array NotReportedTransactionNatures = ['12'];

    /**
     * Tabella B, third column: letters for the buyer-reseller of a triangular operation (INTRA-1 bis only, fiscal data
     * only, no column B). Not reported in INTRA-2 bis since 2018.
     *
     * @var array<string, string>
     */
    public const array TriangularNatureLetters = ['A' => '1', 'B' => '2', 'C' => '3', 'D' => '4', 'E' => '5', 'F' => '7', 'G' => '8', 'H' => '9'];

    /**
     * Tabella C.
     *
     * @var array<string, string>
     */
    public const array TransportModes = [
        '1' => 'Trasporto marittimo',
        '2' => 'Trasporto ferroviario',
        '3' => 'Trasporto stradale',
        '4' => 'Trasporto aereo',
        '5' => 'Spedizioni postali',
        '7' => 'Installazioni fisse di trasporto',
        '8' => 'Trasporto per vie d\'acqua',
        '9' => 'Propulsione propria',
    ];

    /**
     * Tabella D: group code => Incoterms of the group. The table marks DDP as not to be used in the INTRA lists.
     *
     * @var array<string, list<string>>
     */
    public const array DeliveryTermGroups = [
        'E' => ['EXW'],
        'F' => ['FCA', 'FAS', 'FOB'],
        'C' => ['CFR', 'CIF', 'CPT', 'CIP'],
        'D' => ['DAP', 'DPU'],
    ];

    /**
     * Modalità di erogazione: I = istantanea, R = a più riprese.
     *
     * @var array<string, string>
     */
    public const array SupplyModes = ['I' => 'Istantanea', 'R' => 'A più riprese'];

    /**
     * Modalità di incasso / pagamento: B = bonifico, A = accredito, X = altro.
     *
     * @var array<string, string>
     */
    public const array PaymentMethods = ['B' => 'Bonifico', 'A' => 'Accredito', 'X' => 'Altro'];

    /**
     * Frontespizio, field 11 "Contenuto degli elenchi".
     *
     * @var array<int, string>
     */
    public const array ContentCodes = [
        0 => 'Operations of the whole month or quarter',
        8 => 'Change of periodicity: the quarterly list covers the first month only',
        9 => 'Change of periodicity: the quarterly list covers the first and second month',
    ];

    /**
     * Frontespizio, field 12 "Casi particolari riferiti al soggetto obbligato".
     *
     * @var array<int, string>
     */
    public const array SpecialCaseCodes = [
        0 => 'None of the cases below',
        7 => 'First list filed',
        8 => 'Cessation of activity or change of VAT number',
        9 => 'First list filed by a party that, in the same period, ceased activity or changed VAT number',
    ];

    /**
     * Codice unico for shipments under 1,000 euro, in place of the detailed combined nomenclature code.
     */
    public const string SimplifiedCombinedNomenclatureCode = '99500000';

    /**
     * The code of a member state as the INTRA lists want it, or null when the country is not in Tabella A.
     */
    public static function memberState(?string $country): ?string
    {
        $code = strtoupper(trim((string) $country));
        $code = self::IsoToIntrastatCountry[$code] ?? $code;

        return array_key_exists($code, self::MemberStateVatLengths) ? $code : null;
    }

    /**
     * Whether the country is a member state other than Italy, a possible partner of an intra-community operation.
     */
    public static function isPartnerMemberState(?string $country): bool
    {
        $code = self::memberState($country);

        return $code !== null && $code !== self::Italy;
    }

    /**
     * The VAT code without separators and without the leading country code, as Tabella A counts its characters.
     */
    public static function vatCode(?string $vatNumber, string $memberState): ?string
    {
        $code = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $vatNumber));

        foreach (array_unique([$memberState, array_search($memberState, self::IsoToIntrastatCountry, true) ?: $memberState]) as $prefix) {
            if (str_starts_with($code, $prefix) && strlen($code) > strlen($prefix)) {
                $code = substr($code, strlen($prefix));

                break;
            }
        }

        return $code === '' ? null : $code;
    }

    public static function isValidVatCode(string $memberState, string $vatCode): bool
    {
        $lengths = self::MemberStateVatLengths[$memberState] ?? null;

        return $lengths !== null && preg_match('/^[A-Z0-9]+$/', $vatCode) === 1 && in_array(strlen($vatCode), $lengths, true);
    }

    public static function isTriangularNature(?string $natureA): bool
    {
        return $natureA !== null && array_key_exists($natureA, self::TriangularNatureLetters);
    }

    /**
     * Everything a client needs to fill the lists, for the code list endpoint.
     *
     * @return array<string, mixed>
     */
    public static function catalogue(): array
    {
        return [
            'member_states' => array_map(static fn (array $lengths): array => ['vat_lengths' => $lengths], self::MemberStateVatLengths),
            'transaction_natures' => self::TransactionNatures,
            'not_reported_transaction_natures' => self::NotReportedTransactionNatures,
            'triangular_nature_letters' => self::TriangularNatureLetters,
            'transport_modes' => self::TransportModes,
            'delivery_term_groups' => self::DeliveryTermGroups,
            'supply_modes' => self::SupplyModes,
            'payment_methods' => self::PaymentMethods,
            'content_codes' => self::ContentCodes,
            'special_case_codes' => self::SpecialCaseCodes,
            'simplified_combined_nomenclature_code' => self::SimplifiedCombinedNomenclatureCode,
        ];
    }
}
