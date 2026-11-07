<?php

declare(strict_types=1);

namespace Modules\Intrastat\Services;

use Illuminate\Support\Carbon;
use InvalidArgumentException;
use LogicException;
use Modules\Intrastat\Enums\IntrastatFlow;
use Modules\Intrastat\Enums\Periodicity;
use Modules\Intrastat\Models\IntrastatDeclaration;
use Modules\Intrastat\Models\IntrastatDeclarationLine;

/**
 * Writes the telematic file of the INTRA lists (fixed-position fields, one record per line).
 *
 * Sources:
 * - ADM, determinazione prot. n. 493869/RU del 23 dicembre 2021, Allegato XII "Specifiche tecniche e tracciati record
 *   degli elenchi riepilogativi delle cessioni e degli acquisti intracomunitari di beni e dei servizi resi e ricevuti"
 *   (file name, ASCII, records ending in CR LF, alignment and padding, parte fissa, frontespizio INTRA-1 and INTRA-2,
 *   sections 1 to 4 of every model, encoding of a negative total of section 2), kept in force by the determinazione
 *   prot. n. 84415 del 3 febbraio 2026, art. 2:
 *   https://www.adm.gov.it/portale/documents/20182/23456462/Allegato+12+Specifiche+tecniche+e+tracciati+record+degli+elenchi+riepilogativi.pdf/70a17dc9-8170-784c-7868-f154ec6990c8?t=1640615575812
 * - ADM, Servizio Telematico Doganale, "Appendice Tracciati E.D.I." versione del 24 febbraio 2022, par. 2.1 "Record di
 *   testa" (Tabella 5, national user): https://telematico.adm.gov.it/manuali/Tracciati.pdf
 * - Agenzia delle Entrate, Allegato I alla determinazione prot. n. 63336/RU del 7 maggio 2010, record di testa for the
 *   files sent through Entratel (user code ZENT, VAT number left aligned in field 7, progressivo sede 001):
 *   https://telematici.agenziaentrate.gov.it/pdf/AllegatoIDeterminazione63336.pdf
 *
 * Every field below carries the start position and the length of the specifications; the writer checks that each
 * field starts where the specification says. Alphanumeric fields (X) are left aligned and padded with spaces, numeric
 * fields (9) right aligned and padded with zeros; empty fields are all spaces or all zeros. Records are written at the
 * full length of their layout. The quarterly section 1 and 2 layouts of INTRA-2 and section 5 (call-off stock) are not
 * written: the first apply only to periods before 2022, the second is outside the scope of this module.
 */
final class TelematicFileWriter
{
    public const string RecordTerminator = "\r\n";

    /**
     * Record di testa, national user (STD Appendice, Tabella 5). Length 75.
     *
     * @var list<array{0: int, 1: int, 2: string, 3: string}>
     */
    public const array HeaderLayout = [
        [1, 4, 'X', 'user_code'],
        [5, 12, 'X', 'reserved'],
        [17, 12, 'X', 'file_name'],
        [29, 12, 'X', 'reserved'],
        [41, 6, '9', 'customs_section_code'],
        [47, 4, 'X', 'reserved'],
        [51, 16, 'X', 'sender_code'],
        [67, 3, '9', 'site_progressive'],
        [70, 1, 'X', 'reserved'],
        [71, 5, '9', 'record_count'],
    ];

    /**
     * Parte fissa, positions 1 to 28 of every record of a list.
     *
     * @var list<array{0: int, 1: int, 2: string, 3: string}>
     */
    public const array FixedPartLayout = [
        [1, 5, 'X', 'fixed_code'],
        [6, 11, '9', 'presenter_vat_number'],
        [17, 6, '9', 'reference_number'],
        [23, 1, '9', 'record_type'],
        [24, 5, '9', 'line_number'],
    ];

    /**
     * Frontespizio INTRA-1 (cessioni). INTRA-2 stops at field 21 (position 130): field 22 is not present for purchases
     * from 2022.
     *
     * @var list<array{0: int, 1: int, 2: string, 3: string}>
     */
    public const array FrontespizioLayout = [
        [29, 1, 'X', 'summary_type'],
        [30, 2, '9', 'year'],
        [32, 1, 'X', 'periodicity'],
        [33, 2, '9', 'period'],
        [35, 11, '9', 'obligated_vat_number'],
        [46, 1, '9', 'content_code'],
        [47, 1, '9', 'special_case_code'],
        [48, 11, '9', 'delegate_vat_number'],
        [59, 5, '9', 'section_1_count'],
        [64, 13, '9', 'section_1_total'],
        [77, 5, '9', 'section_2_count'],
        [82, 13, 'S', 'section_2_total'],
        [95, 5, '9', 'section_3_count'],
        [100, 13, '9', 'section_3_total'],
        [113, 5, '9', 'section_4_count'],
        [118, 13, '9', 'section_4_total'],
        [131, 5, '9', 'section_5_count'],
    ];

    /**
     * Detail layouts by flow, section and periodicity.
     *
     * @var array<string, list<array{0: int, 1: int, 2: string, 3: string}>>
     */
    public const array DetailLayouts = [
        // INTRA-1 bis, cessioni di beni, mensile. Length 106.
        'sales.1.M' => [
            [29, 2, 'X', 'partner_country_code'],
            [31, 12, 'X', 'partner_vat_number'],
            [43, 13, '9', 'amount_eur'],
            [56, 1, 'X', 'transaction_nature_a'],
            [57, 8, '9', 'cn8_code'],
            [65, 10, '9', 'net_mass_kg'],
            [75, 10, '9', 'supplementary_units'],
            [85, 13, '9', 'statistical_value_eur'],
            [98, 1, 'X', 'delivery_terms'],
            [99, 1, '9', 'transport_mode'],
            [100, 2, 'X', 'goods_country_code'],
            [102, 2, 'X', 'province_code'],
            [104, 1, 'X', 'transaction_nature_b'],
            [105, 2, 'X', 'origin_country_code'],
        ],
        // INTRA-1 bis, cessioni di beni, trimestrale. Length 64.
        'sales.1.T' => [
            [29, 2, 'X', 'partner_country_code'],
            [31, 12, 'X', 'partner_vat_number'],
            [43, 13, '9', 'amount_eur'],
            [56, 1, 'X', 'transaction_nature_a'],
            [57, 8, '9', 'cn8_code'],
        ],
        // INTRA-1 ter, rettifiche alle cessioni di beni, mensile. Length 83.
        'sales.2.M' => [
            [29, 2, '9', 'corrected_month'],
            [31, 1, '9', 'corrected_quarter'],
            [32, 2, '9', 'corrected_year'],
            [34, 2, 'X', 'partner_country_code'],
            [36, 12, 'X', 'partner_vat_number'],
            [48, 1, 'X', 'sign'],
            [49, 13, '9', 'amount_eur'],
            [62, 1, 'X', 'transaction_nature_a'],
            [63, 8, '9', 'cn8_code'],
            [71, 13, '9', 'statistical_value_eur'],
        ],
        // INTRA-1 ter, rettifiche alle cessioni di beni, trimestrale. Length 70.
        'sales.2.T' => [
            [29, 2, '9', 'corrected_month'],
            [31, 1, '9', 'corrected_quarter'],
            [32, 2, '9', 'corrected_year'],
            [34, 2, 'X', 'partner_country_code'],
            [36, 12, 'X', 'partner_vat_number'],
            [48, 1, 'X', 'sign'],
            [49, 13, '9', 'amount_eur'],
            [62, 1, 'X', 'transaction_nature_a'],
            [63, 8, '9', 'cn8_code'],
        ],
        // INTRA-1 quater, servizi resi. Length 86.
        'sales.3' => [
            [29, 2, 'X', 'partner_country_code'],
            [31, 12, 'X', 'partner_vat_number'],
            [43, 13, '9', 'amount_eur'],
            [56, 15, 'X', 'invoice_number'],
            [71, 6, 'X', 'invoice_date'],
            [77, 6, '9', 'service_code'],
            [83, 1, 'X', 'supply_mode'],
            [84, 1, 'X', 'payment_method'],
            [85, 2, 'X', 'payment_country_code'],
        ],
        // INTRA-1 quinquies, rettifiche ai servizi resi. Length 105.
        'sales.4' => [
            [29, 6, '9', 'corrected_customs_section'],
            [35, 2, '9', 'corrected_protocol_year'],
            [37, 6, '9', 'corrected_protocol_number'],
            [43, 5, '9', 'corrected_line_number'],
            [48, 2, 'X', 'partner_country_code'],
            [50, 12, 'X', 'partner_vat_number'],
            [62, 13, '9', 'amount_eur'],
            [75, 15, 'X', 'invoice_number'],
            [90, 6, 'X', 'invoice_date'],
            [96, 6, '9', 'service_code'],
            [102, 1, 'X', 'supply_mode'],
            [103, 1, 'X', 'payment_method'],
            [104, 2, 'X', 'payment_country_code'],
        ],
        // INTRA-2 bis, acquisti di beni, mensile. Length 119.
        'purchases.1.M' => [
            [29, 2, 'X', 'partner_country_code'],
            [31, 12, 'X', 'partner_vat_number'],
            [43, 13, '9', 'amount_eur'],
            [56, 13, '9', 'amount_currency'],
            [69, 1, 'X', 'transaction_nature_a'],
            [70, 8, '9', 'cn8_code'],
            [78, 10, '9', 'net_mass_kg'],
            [88, 10, '9', 'supplementary_units'],
            [98, 13, '9', 'statistical_value_eur'],
            [111, 1, 'X', 'delivery_terms'],
            [112, 1, '9', 'transport_mode'],
            [113, 2, 'X', 'goods_country_code'],
            [115, 2, 'X', 'origin_country_code'],
            [117, 2, 'X', 'province_code'],
            [119, 1, 'X', 'transaction_nature_b'],
        ],
        // INTRA-2 ter, rettifiche agli acquisti di beni, mensile. Length 96.
        'purchases.2.M' => [
            [29, 2, '9', 'corrected_month'],
            [31, 1, '9', 'corrected_quarter'],
            [32, 2, '9', 'corrected_year'],
            [34, 2, 'X', 'partner_country_code'],
            [36, 12, 'X', 'partner_vat_number'],
            [48, 1, 'X', 'sign'],
            [49, 13, '9', 'amount_eur'],
            [62, 13, '9', 'amount_currency'],
            [75, 1, 'X', 'transaction_nature_a'],
            [76, 8, '9', 'cn8_code'],
            [84, 13, '9', 'statistical_value_eur'],
        ],
        // INTRA-2 quater, servizi ricevuti. Length 99.
        'purchases.3' => [
            [29, 2, 'X', 'partner_country_code'],
            [31, 12, 'X', 'partner_vat_number'],
            [43, 13, '9', 'amount_eur'],
            [56, 13, '9', 'amount_currency'],
            [69, 15, 'X', 'invoice_number'],
            [84, 6, 'X', 'invoice_date'],
            [90, 6, '9', 'service_code'],
            [96, 1, 'X', 'supply_mode'],
            [97, 1, 'X', 'payment_method'],
            [98, 2, 'X', 'payment_country_code'],
        ],
        // INTRA-2 quinquies, rettifiche ai servizi ricevuti. Length 118.
        'purchases.4' => [
            [29, 6, '9', 'corrected_customs_section'],
            [35, 2, '9', 'corrected_protocol_year'],
            [37, 6, '9', 'corrected_protocol_number'],
            [43, 5, '9', 'corrected_line_number'],
            [48, 2, 'X', 'partner_country_code'],
            [50, 12, 'X', 'partner_vat_number'],
            [62, 13, '9', 'amount_eur'],
            [75, 13, '9', 'amount_currency'],
            [88, 15, 'X', 'invoice_number'],
            [103, 6, 'X', 'invoice_date'],
            [109, 6, '9', 'service_code'],
            [115, 1, 'X', 'supply_mode'],
            [116, 1, 'X', 'payment_method'],
            [117, 2, 'X', 'payment_country_code'],
        ],
    ];

    /**
     * Allegato XII, nota al campo 17: the last digit of a negative total is replaced by a letter.
     *
     * @var array<string, string>
     */
    public const array NegativeLastDigit = ['0' => 'p', '1' => 'q', '2' => 'r', '3' => 's', '4' => 't', '5' => 'u', '6' => 'v', '7' => 'w', '8' => 'x', '9' => 'y'];

    /**
     * File name <codiceUA><MMGG>.I<nn> (Allegato XII, point 1).
     */
    public static function fileName(string $userCode, Carbon $transmissionDate, string $sequence): string
    {
        return strtoupper($userCode).$transmissionDate->format('md').'.I'.strtoupper($sequence);
    }

    /**
     * @param  iterable<IntrastatDeclaration>  $declarations
     * @param  array{user_code: string, customs_section_code: string, sender_code: string, site_progressive: int, file_name: string}  $header
     */
    public function write(iterable $declarations, array $header): string
    {
        $records = [];

        foreach ($declarations as $declaration) {
            array_push($records, ...$this->declarationRecords($declaration));
        }

        array_unshift($records, $this->record(self::HeaderLayout, [...$header, 'reserved' => null, 'record_count' => count($records) + 1]));

        return implode(self::RecordTerminator, $records).self::RecordTerminator;
    }

    /**
     * @return list<string>
     */
    public function declarationRecords(IntrastatDeclaration $declaration): array
    {
        $declaration->loadMissing('lines');
        $isSales = $declaration->flow === IntrastatFlow::Sales;
        $bySection = $declaration->lines->groupBy('section');

        $fixed = static fn (int $type, int $lineNumber): array => [
            'fixed_code' => 'EUROX',
            'presenter_vat_number' => $declaration->presenterVatNumber(),
            'reference_number' => $declaration->reference_number,
            'record_type' => $type,
            'line_number' => $lineNumber,
        ];

        $totals = [];

        foreach ([1, 2, 3, 4] as $section) {
            $lines = $bySection->get($section, collect());
            $totals["section_{$section}_count"] = $lines->count();
            $totals["section_{$section}_total"] = $lines->sum(static fn (IntrastatDeclarationLine $line): int => $section === 2 && $line->sign === '-' ? -((int) $line->amount_eur) : (int) $line->amount_eur);
        }

        $frontespizio = $isSales ? self::FrontespizioLayout : array_slice(self::FrontespizioLayout, 0, -1);
        $records = [$this->record([...self::FixedPartLayout, ...$frontespizio], [
            ...$fixed(0, 0),
            ...$totals,
            'section_5_count' => 0,
            'summary_type' => $declaration->flow->summaryType(),
            'year' => $declaration->year % 100,
            'periodicity' => $declaration->periodicity->value,
            'period' => $declaration->period,
            'obligated_vat_number' => $declaration->obligated_vat_number,
            'content_code' => $declaration->content_code,
            'special_case_code' => $declaration->special_case_code,
            'delegate_vat_number' => $declaration->delegate_vat_number,
        ])];

        foreach ([1, 2, 3, 4] as $section) {
            $layout = self::DetailLayouts[$this->layoutKey($declaration, $section)] ?? null;

            foreach ($bySection->get($section, collect()) as $line) {
                if ($layout === null) {
                    throw new LogicException("No record layout for section {$section} of this list.");
                }

                $records[] = $this->record([...self::FixedPartLayout, ...$layout], [...$fixed($section, $line->line_number), ...$this->lineValues($line)]);
            }
        }

        return $records;
    }

    private function layoutKey(IntrastatDeclaration $declaration, int $section): string
    {
        $key = $declaration->flow->value.'.'.$section;

        return in_array($section, [1, 2], true) ? $key.'.'.($declaration->periodicity === Periodicity::Monthly ? 'M' : 'T') : $key;
    }

    /**
     * @return array<string, mixed>
     */
    private function lineValues(IntrastatDeclarationLine $line): array
    {
        $values = $line->attributesToArray();
        $values['invoice_date'] = $line->invoice_date?->format('dmy');
        $values['corrected_year'] = $line->corrected_year !== null ? $line->corrected_year % 100 : null;
        $values['corrected_protocol_year'] = $line->corrected_protocol_year !== null ? $line->corrected_protocol_year % 100 : null;

        return $values;
    }

    /**
     * @param  list<array{0: int, 1: int, 2: string, 3: string}>  $layout
     * @param  array<string, mixed>  $values
     */
    private function record(array $layout, array $values): string
    {
        $record = '';

        foreach ($layout as [$start, $length, $type, $key]) {
            if (strlen($record) !== $start - 1) {
                throw new LogicException("Field {$key} should start at position {$start}, not ".(strlen($record) + 1).'.');
            }

            $record .= match ($type) {
                'X' => $this->alphanumeric($values[$key] ?? null, $length, $key),
                '9' => $this->numeric($values[$key] ?? null, $length, $key),
                'S' => $this->signedNumeric((int) ($values[$key] ?? 0), $length, $key),
            };
        }

        return $record;
    }

    private function alphanumeric(mixed $value, int $length, string $key): string
    {
        $text = (string) ($value ?? '');

        if (strlen($text) > $length || preg_match('/^[\x20-\x7E]*$/', $text) !== 1) {
            throw new InvalidArgumentException("Field {$key} does not fit {$length} ASCII characters.");
        }

        return str_pad($text, $length, ' ', STR_PAD_RIGHT);
    }

    private function numeric(mixed $value, int $length, string $key): string
    {
        $text = (string) ($value ?? '0');

        if ($text === '' || preg_match('/^\d+$/', $text) !== 1 || strlen($text) > $length) {
            throw new InvalidArgumentException("Field {$key} is not a number of at most {$length} digits.");
        }

        return str_pad($text, $length, '0', STR_PAD_LEFT);
    }

    private function signedNumeric(int $value, int $length, string $key): string
    {
        $digits = $this->numeric((string) abs($value), $length, $key);

        return $value < 0 ? substr($digits, 0, -1).self::NegativeLastDigit[substr($digits, -1)] : $digits;
    }
}
