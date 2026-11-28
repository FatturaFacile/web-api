<?php

declare(strict_types=1);

use Modules\DairyCheeseManufacturing\Domain\Enums\LabFormat;
use Modules\DairyCheeseManufacturing\Domain\Lab\LabParser;

it('reads a Suisselab CSV: German headers, decimal comma, thousands per ml and milli-degrees', function (): void {
    $csv = "TVD-Nr;BDM-Nr;Probedatum;Fett;Eiweiss;Keimzahl;Zellzahl;Gefrierpunkt;Hemmstoffe;Harnstoff\nCH123;BDM-1;12.05.2026;4,10;3,40;12;180;-520;negativ;250\n";

    $rows = (new LabParser)->parse($csv, LabFormat::Suisselab);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['errors'])->toBe([])
        ->and($rows[0]['identifiers'])->toBe(['tvd' => 'CH123', 'bdm' => 'BDM-1'])
        ->and($rows[0]['data'])->toMatchArray([
            'sample_date' => '2026-05-12', 'period_start' => '2026-05-01', 'period_end' => '2026-05-31', 'fat_pct' => 4.1, 'protein_pct' => 3.4,
            'tbc_cfu_ml' => 12000, 'scc_cells_ml' => 180000, 'freezing_point_c' => -0.52, 'inhibitors' => 'negative', 'urea_mg_l' => 250.0,
        ]);
});

it('reads an AIA CSV: Italian headers, slash dates, the freezing point already in degrees and a positive inhibitor', function (): void {
    $csv = "CodiceAllevatore;CUAA;DataCampione;Grasso;Proteine;CBT;CCS;Crioscopia;Inibitori\nIT001;CUAA001;12/05/2026;3,85;3,30;45;420;-0,518;positivo\n";

    $row = (new LabParser)->parse($csv, LabFormat::Aia)[0];

    expect($row['errors'])->toBe([])
        ->and($row['identifiers'])->toBe(['code' => 'IT001', 'cuaa' => 'CUAA001'])
        ->and($row['data'])->toMatchArray(['sample_date' => '2026-05-12', 'fat_pct' => 3.85, 'tbc_cfu_ml' => 45000, 'scc_cells_ml' => 420000, 'freezing_point_c' => -0.518, 'inhibitors' => 'positive']);
});

it('reads a TSM XML file', function (): void {
    $xml = '<?xml version="1.0"?><Milchanalysen><Probe><TVD>CH123</TVD><Datum>2026-05-12</Datum><Fett>4.1</Fett><Eiweiss>3.4</Eiweiss><Keimzahl>12</Keimzahl><Zellzahl>180</Zellzahl><Gefrierpunkt>-520</Gefrierpunkt><Hemmstoffe>negativ</Hemmstoffe></Probe>'
        .'<Probe><TVD>CH124</TVD><Datum>2026-05-13</Datum><Fett>3.9</Fett></Probe></Milchanalysen>';

    $rows = (new LabParser)->parse($xml, LabFormat::Tsm);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['data'])->toMatchArray(['fat_pct' => 4.1, 'scc_cells_ml' => 180000, 'freezing_point_c' => -0.52, 'inhibitors' => 'negative'])
        ->and($rows[1]['identifiers'])->toBe(['tvd' => 'CH124'])
        ->and($rows[1]['data']['inhibitors'])->toBe('not_tested');
});

it('reads an ARAL XML file and attributes', function (): void {
    $xml = '<?xml version="1.0"?><Campioni><Campione CUAA="CUAA001"><Data>2026-05-12</Data><Grasso>3.85</Grasso><CCS>420</CCS><Inibitori>positivo</Inibitori></Campione></Campioni>';

    $row = (new LabParser)->parse($xml, LabFormat::Aral)[0];

    expect($row['identifiers'])->toBe(['cuaa' => 'CUAA001'])
        ->and($row['data'])->toMatchArray(['scc_cells_ml' => 420000, 'inhibitors' => 'positive']);
});

it('reads a generic CSV with plain units and either delimiter', function (): void {
    $comma = "breeder_code,sample_date,fat_pct,scc_cells_ml,tbc_cfu_ml\nCH-001,2026-05-12,4.20,150000,20000\n";
    $semi = "breeder_code;sample_date;fat_pct;scc_cells_ml\nCH-001;2026-05-12;4.20;150000\n";

    expect((new LabParser)->parse($comma, LabFormat::GenericCsv)[0]['data'])->toMatchArray(['fat_pct' => 4.2, 'scc_cells_ml' => 150000, 'tbc_cfu_ml' => 20000])
        ->and((new LabParser)->parse($semi, LabFormat::GenericCsv)[0]['data']['scc_cells_ml'])->toBe(150000);
});

it('reports why a row cannot be used', function (): void {
    $csv = "breeder_code,sample_date,fat_pct,inhibitors\n,2026-05-12,4.2,negative\nCH-001,31/02/2026,4.2,negative\nCH-001,2026-05-12,99,negative\nCH-001,2026-05-12,4.2,maybe\nCH-001,2026-05-12,,\n";

    $rows = (new LabParser)->parse($csv, LabFormat::GenericCsv);

    expect($rows[0]['errors'])->toContain('breeder_identifier_missing')
        ->and($rows[1]['errors'])->toContain('sample_date_invalid')
        ->and($rows[2]['errors'])->toContain('fat_pct_out_of_range')
        ->and($rows[3]['errors'])->toContain('inhibitors_invalid')
        ->and($rows[4]['errors'])->toContain('no_measurements');
});

it('does not fail on empty content or broken XML', function (): void {
    expect((new LabParser)->parse('', LabFormat::GenericCsv))->toBe([])
        ->and((new LabParser)->parse('<broken', LabFormat::Tsm)[0]['errors'])->toBe(['xml_invalid']);
});

it('does not expand external entities of the XML', function (): void {
    $xml = '<?xml version="1.0"?><!DOCTYPE r [<!ENTITY x SYSTEM "file:///etc/passwd">]><Milchanalysen><Probe><TVD>&x;</TVD><Datum>2026-05-12</Datum><Fett>4.1</Fett></Probe></Milchanalysen>';

    $row = (new LabParser)->parse($xml, LabFormat::Tsm)[0];

    expect($row['identifiers'])->not->toHaveKey('tvd');
});
