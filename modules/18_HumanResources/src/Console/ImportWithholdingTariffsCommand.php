<?php

declare(strict_types=1);

namespace Modules\HumanResources\Console;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Modules\HumanResources\Contracts\Payroll\WithholdingTariffImporterInterface;
use Modules\HumanResources\Exceptions\Payroll\WithholdingTariffImportException;

/**
 * Imports Swiss withholding tax tariffs from a CSV file in the internal format. Without `--tenant` the
 * tariffs become system defaults shared by every tenant.
 */
final class ImportWithholdingTariffsCommand extends Command
{
    protected $signature = 'hr:payroll:import-tariffs {file : Path to the CSV tariff file} {--tenant= : Import as tariffs of this tenant UUID}';

    protected $description = 'Import Swiss withholding tax (Quellensteuer) tariffs from a CSV file';

    public function handle(WithholdingTariffImporterInterface $importer, TenantContext $tenantContext): int
    {
        $file = (string) $this->argument('file');

        if (! is_file($file) || ! is_readable($file)) {
            $this->error("File {$file} does not exist or is not readable.");

            return self::FAILURE;
        }

        $contents = (string) file_get_contents($file);
        $tenantId = $this->option('tenant');

        try {
            if (is_string($tenantId) && $tenantId !== '') {
                $tenant = Tenant::query()->find($tenantId);

                if ($tenant === null) {
                    $this->error("Tenant {$tenantId} was not found.");

                    return self::FAILURE;
                }

                $result = $tenantContext->runWithTenant($tenant, fn (): array => $importer->import($contents, (string) $tenant->id));
            } else {
                $result = $importer->import($contents);
            }
        } catch (WithholdingTariffImportException $exception) {
            $this->error($exception->getMessage());

            foreach ($exception->invalidParams() as $field => $messages) {
                $this->line("  {$field}: ".implode(' ', $messages));
            }

            return self::FAILURE;
        }

        $this->info("Imported {$result['tariffs']} tariff(s) with {$result['brackets']} bracket(s).");

        return self::SUCCESS;
    }
}
