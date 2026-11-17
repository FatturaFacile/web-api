<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\ServiceManagement\FieldService\Commands\GeneratePreventiveMaintenanceWorkOrdersCommand;
use Modules\ServiceManagement\FieldService\Commands\GeneratePreventiveMaintenanceWorkOrdersHandler;

/**
 * Daily preventive maintenance run of one tenant (GH-197). Idempotent: a maintenance cycle never gets a second work order.
 */
final class GeneratePreventiveMaintenanceWorkOrdersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly string $tenantId)
    {
        $this->afterCommit();
    }

    public function handle(GeneratePreventiveMaintenanceWorkOrdersHandler $handler): void
    {
        $handler(new GeneratePreventiveMaintenanceWorkOrdersCommand($this->tenantId));
    }
}
