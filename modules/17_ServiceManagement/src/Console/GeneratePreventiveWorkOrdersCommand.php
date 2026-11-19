<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Console;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Modules\ServiceManagement\FieldService\Commands\GeneratePreventiveMaintenanceWorkOrdersCommand;
use Modules\ServiceManagement\FieldService\Commands\GeneratePreventiveMaintenanceWorkOrdersHandler;

final class GeneratePreventiveWorkOrdersCommand extends Command
{
    protected $signature = 'esm:preventive:generate {--tenant= : Limit the run to one tenant UUID}';

    protected $description = 'Generate the preventive maintenance work orders that are due (idempotent per maintenance cycle)';

    public function handle(GeneratePreventiveMaintenanceWorkOrdersHandler $handler): int
    {
        $tenants = $this->option('tenant') !== null ? [(string) $this->option('tenant')] : Tenant::query()->pluck('id')->map(static fn ($id): string => (string) $id)->all();
        $created = 0;

        foreach ($tenants as $tenantId) {
            $created += count($handler(new GeneratePreventiveMaintenanceWorkOrdersCommand($tenantId))['created']);
        }

        $this->info("Preventive maintenance run completed: {$created} work order(s) created.");

        return self::SUCCESS;
    }
}
