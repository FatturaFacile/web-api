<?php

declare(strict_types=1);

namespace Modules\ItalianVat\Services;

/**
 * Position tables of the CU telematic supply (record length 1900 bytes: 1897 data bytes, the end marker in position 1898 and
 * CR LF in 1899-1900).
 *
 * Specification profile: "Specifiche tecniche per la trasmissione telematica delle Certificazioni Uniche" of the Agenzia
 * delle Entrate (CU supply, record length 1900, fornitura code CURyy), with the record families used by this module for
 * lavoro autonomo and provvigioni certifications:
 *  - A  record di testa (supply head, sender kind and tax code, counts);
 *  - B  frontespizio della fornitura (sender data: denomination, address, contacts, creation date);
 *  - C  sostituto d'imposta (withholding agent identity);
 *  - D  percipiente (recipient identity and certification header), one per certification;
 *  - E  dati fiscali e previdenziali (amounts in euro cents), one per certification, same progressive as its D;
 *  - H  riepilogo (totals of the supply);
 *  - Z  record di coda (record counts per family, grand total, control total).
 * The record families follow the labelling of the official supply, but the exact field offsets are those declared here: the
 * produced file must be checked with the control software of the Agenzia delle Entrate of the year before it is transmitted
 * through Entratel or the Desktop Telematico.
 *
 * Each field is [position (1-based), length, kind]; kind is NUMERIC (right-justified, zero padded), ALPHA (upper case,
 * left-justified, space padded) or DATE (ddmmyyyy, spaces when empty).
 */
final class CuRecordLayout
{
    public const int RECORD_LENGTH = 1900;

    public const string NUMERIC = 'n';

    public const string ALPHA = 'a';

    public const string DATE = 'd';

    /** Control character in the last data position of every record. */
    public const string END_MARKER = 'A';

    /** Supply code prefix; the last two digits of the tax year follow. */
    public const string SUPPLY_CODE = 'CUR';

    /** Position of the supply code in the A record. */
    public const int SUPPLY_CODE_POSITION = 16;

    /** Tipo fornitore: 01 = the taxpayer sends its own certifications. */
    public const string SUPPLIER_TYPE = '01';

    /** Order of the record families in a supply: A, B, C, then a D/E pair per certification, then H and Z. */
    public const array FAMILIES = ['A', 'B', 'C', 'D', 'E', 'H', 'Z'];

    public const array A = [
        'supplier_type' => [21, 2, self::NUMERIC],
        'tax_code' => [23, 16, self::ALPHA],
        'year' => [43, 4, self::NUMERIC],
        'certifications' => [47, 7, self::NUMERIC],
        'creation_date' => [54, 8, self::DATE],
    ];

    public const array B = [
        'substitute_tax_code' => [2, 16, self::ALPHA],
        'progressive' => [18, 7, self::NUMERIC],
        'sender_kind' => [25, 2, self::NUMERIC],
        'sender_tax_code' => [27, 16, self::ALPHA],
        'denomination' => [43, 60, self::ALPHA],
        'street' => [103, 40, self::ALPHA],
        'postal_code' => [143, 5, self::NUMERIC],
        'city' => [148, 40, self::ALPHA],
        'province' => [188, 2, self::ALPHA],
        'phone' => [190, 20, self::ALPHA],
        'email' => [210, 60, self::ALPHA],
        'creation_date' => [270, 8, self::DATE],
        'year' => [278, 4, self::NUMERIC],
        'certifications' => [282, 7, self::NUMERIC],
    ];

    public const array C = [
        'substitute_tax_code' => [2, 16, self::ALPHA],
        'progressive' => [18, 7, self::NUMERIC],
        'vat_number' => [25, 11, self::NUMERIC],
        'denomination' => [37, 60, self::ALPHA],
        'year' => [97, 4, self::NUMERIC],
    ];

    public const array D = [
        'substitute_tax_code' => [2, 16, self::ALPHA],
        'progressive' => [18, 7, self::NUMERIC],
        'recipient_tax_code' => [25, 16, self::ALPHA],
        'recipient_kind' => [41, 1, self::ALPHA],
        'recipient_name' => [42, 60, self::ALPHA],
        'reason' => [102, 2, self::ALPHA],
        'year' => [104, 4, self::NUMERIC],
        'certificate_date' => [108, 8, self::DATE],
        'payments' => [116, 5, self::NUMERIC],
    ];

    public const array E = [
        'substitute_tax_code' => [2, 16, self::ALPHA],
        'progressive' => [18, 7, self::NUMERIC],
        'recipient_tax_code' => [25, 16, self::ALPHA],
        'reason' => [41, 2, self::ALPHA],
        'gross' => [43, 15, self::NUMERIC],
        'non_taxable' => [58, 15, self::NUMERIC],
        'taxable' => [73, 15, self::NUMERIC],
        'withheld' => [88, 15, self::NUMERIC],
        'contributions' => [103, 15, self::NUMERIC],
        'year' => [118, 4, self::NUMERIC],
    ];

    public const array H = [
        'substitute_tax_code' => [2, 16, self::ALPHA],
        'progressive' => [18, 7, self::NUMERIC],
        'certifications' => [25, 7, self::NUMERIC],
        'gross' => [32, 17, self::NUMERIC],
        'non_taxable' => [49, 17, self::NUMERIC],
        'taxable' => [66, 17, self::NUMERIC],
        'withheld' => [83, 17, self::NUMERIC],
        'contributions' => [100, 17, self::NUMERIC],
    ];

    public const array Z = [
        'a_records' => [17, 7, self::NUMERIC],
        'b_records' => [24, 7, self::NUMERIC],
        'c_records' => [31, 7, self::NUMERIC],
        'd_records' => [38, 7, self::NUMERIC],
        'e_records' => [45, 7, self::NUMERIC],
        'h_records' => [52, 7, self::NUMERIC],
        'total_records' => [59, 9, self::NUMERIC],
        'control_total' => [68, 15, self::NUMERIC],
    ];

    /**
     * @return array<string, array<string, array{0: int, 1: int, 2: string}>>
     */
    public static function all(): array
    {
        return ['A' => self::A, 'B' => self::B, 'C' => self::C, 'D' => self::D, 'E' => self::E, 'H' => self::H, 'Z' => self::Z];
    }

    /**
     * @return array{0: int, 1: int, 2: string}
     */
    public static function field(string $family, string $name): array
    {
        return self::all()[$family][$name];
    }
}
