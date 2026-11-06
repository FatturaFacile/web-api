<?php

declare(strict_types=1);

namespace Modules\Payroll\Console\Commands;

use Illuminate\Console\Command;
use Modules\Payroll\Services\WithholdingTaxTariffImporter;

/**
 * Loads an ESTV tariff file as generic data, shared by every company of the installation.
 */
final class ImportGenericTariffsCommand extends Command
{
    protected $signature = 'payroll:import-tariffs {file : Path of the ESTV tariff file}';

    protected $description = 'Import an ESTV withholding tax tariff file as generic data';

    public function handle(WithholdingTaxTariffImporter $importer): int
    {
        $path = (string) $this->argument('file');
        $content = is_file($path) ? file_get_contents($path) : false;

        if ($content === false) {
            $this->error("Cannot read [{$path}].");

            return self::FAILURE;
        }

        $this->info($importer->import($content, null).' tariff rows imported.');

        return self::SUCCESS;
    }
}
