<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Domain\Lab;

use Modules\DairyCheeseManufacturing\Domain\Enums\LabFormat;

/**
 * How a laboratory format names its fields and which units it uses.
 *  - suisselab (CSV, ';') and tsm (XML): Swiss German headers, counts in thousands per ml, freezing point in milli-degrees or °C.
 *  - aia (CSV, ';') and aral (XML): Italian headers, decimal comma, counts in thousands per ml, freezing point in m°C.
 *  - generic_csv: English headers (the field names), plain units, freezing point in °C.
 * Identifiers (code, tvd, bdm, cuaa, asl, farm) are matched against the breeder registry.
 */
final readonly class LabFormatProfile
{
    /**
     * @param  array<string, string>  $fields  normalized header (lower case, letters and digits only) => field
     */
    public function __construct(
        public bool $xml,
        public array $fields,
        public bool $thousandsCounts,
        public ?string $delimiter,
    ) {}

    public static function for(LabFormat $format): self
    {
        $english = [
            'breedercode' => 'code', 'code' => 'code', 'tvd' => 'tvd', 'bdm' => 'bdm', 'cuaa' => 'cuaa', 'asl' => 'asl', 'farmcode' => 'farm',
            'sampledate' => 'sample_date', 'periodstart' => 'period_start', 'periodend' => 'period_end', 'fatpct' => 'fat_pct', 'proteinpct' => 'protein_pct',
            'caseinpct' => 'casein_pct', 'ureamgl' => 'urea_mg_l', 'tbccfuml' => 'tbc_cfu_ml', 'scccellsml' => 'scc_cells_ml', 'freezingpointc' => 'freezing_point_c',
            'inhibitors' => 'inhibitors', 'laboratory' => 'laboratory',
        ];
        $german = [
            'tvdnr' => 'tvd', 'tvd' => 'tvd', 'bdmnr' => 'bdm', 'bdm' => 'bdm', 'betriebsnr' => 'farm', 'probedatum' => 'sample_date', 'datum' => 'sample_date',
            'periodevon' => 'period_start', 'periodebis' => 'period_end', 'fett' => 'fat_pct', 'eiweiss' => 'protein_pct', 'protein' => 'protein_pct', 'kasein' => 'casein_pct',
            'harnstoff' => 'urea_mg_l', 'keimzahl' => 'tbc_cfu_ml', 'zellzahl' => 'scc_cells_ml', 'gefrierpunkt' => 'freezing_point_c', 'hemmstoffe' => 'inhibitors', 'labor' => 'laboratory',
        ];
        $italian = [
            'codiceallevatore' => 'code', 'allevatore' => 'code', 'cuaa' => 'cuaa', 'codiceasl' => 'asl', 'asl' => 'asl', 'codicestalla' => 'farm', 'datacampione' => 'sample_date', 'data' => 'sample_date',
            'periodoinizio' => 'period_start', 'periodofine' => 'period_end', 'grasso' => 'fat_pct', 'proteine' => 'protein_pct', 'caseina' => 'casein_pct', 'urea' => 'urea_mg_l',
            'cbt' => 'tbc_cfu_ml', 'caricabatterica' => 'tbc_cfu_ml', 'ccs' => 'scc_cells_ml', 'cellulesomatiche' => 'scc_cells_ml', 'crioscopia' => 'freezing_point_c', 'puntocrioscopico' => 'freezing_point_c',
            'crioscopico' => 'freezing_point_c', 'inibitori' => 'inhibitors', 'laboratorio' => 'laboratory',
        ];

        return match ($format) {
            LabFormat::Suisselab => new self(false, $german, true, ';'),
            LabFormat::Tsm => new self(true, $german, true, null),
            LabFormat::Aia => new self(false, $italian, true, ';'),
            LabFormat::Aral => new self(true, $italian, true, null),
            LabFormat::GenericCsv => new self(false, $english, false, null),
        };
    }
}
