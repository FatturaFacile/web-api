<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Customs\Services\CustomsMasterDataImporter;

/**
 * Generic customs code tables, from the BAZG e-dec files kept unchanged in the module (domains version 00427 of
 * 27 October 2026, country codes version 00186 of 25 August 2026). The tariff numbers are not seeded: the file is 62 MB
 * and changes often, so `customs:import-master-data --tariff` loads it from the current BAZG download.
 */
class CustomsReferenceSeeder extends Seeder
{
    public function run(CustomsMasterDataImporter $importer): void
    {
        $directory = base_path('modules/32_Customs/resources/master-data');

        $importer->importDomains($directory.'/edecDomains_1_0.xml');
        $importer->importCountries($directory.'/edecCountryCodes_1_0.xml');
    }
}
