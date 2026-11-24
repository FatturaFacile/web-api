<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Jobs;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\MarketingAutomation\Application\Commands\ExecuteMarketingAutomationJourneyCommand;
use Modules\MarketingAutomation\Application\Commands\ExecuteMarketingAutomationJourneyHandler;

/**
 * Step runner of the automation journeys: enrols new contacts and executes the due steps of the active campaigns.
 */
final class RunMarketingJourneysJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly string $tenantId) {}

    public function handle(ExecuteMarketingAutomationJourneyHandler $handler, TenantContext $context): void
    {
        $tenant = Tenant::query()->findOrFail($this->tenantId);
        $context->runWithTenant($tenant, fn () => $handler->handle(new ExecuteMarketingAutomationJourneyCommand($this->tenantId)));
    }
}
