<?php

declare(strict_types=1);

namespace Modules\Customs\Console;

use Illuminate\Console\Command;
use Modules\Customs\Services\CustomsMasterDataImporter;

/**
 * Loads the e-dec master data files published by BAZG as generic rows.
 */
final class ImportCustomsMasterDataCommand extends Command
{
    protected $signature = 'customs:import-master-data
        {--domains= : Path of edecDomains_1_0.xml}
        {--countries= : Path of edecCountryCodes_1_0.xml}
        {--tariff= : Path of edecTariffMasterData_1_0.xml}';

    protected $description = 'Load the BAZG e-dec master data files as generic customs code tables';

    public function handle(CustomsMasterDataImporter $importer): int
    {
        $domains = $this->option('domains');
        $countries = $this->option('countries');
        $tariff = $this->option('tariff');

        if ($domains === null && $countries === null && $tariff === null) {
            $this->error('Give at least one of --domains, --countries, --tariff.');

            return self::FAILURE;
        }

        if ($domains !== null) {
            $result = $importer->importDomains((string) $domains);
            $this->info("Packaging types: {$result['packaging_types']}, document types: {$result['document_types']}.");
        }

        if ($countries !== null) {
            $this->info('Countries: '.$importer->importCountries((string) $countries).'.');
        }

        if ($tariff !== null) {
            $result = $importer->importTariff((string) $tariff);
            $this->info("Tariff numbers: {$result['tariff_numbers']}, statistical keys: {$result['statistical_keys']}.");
        }

        return self::SUCCESS;
    }
}
