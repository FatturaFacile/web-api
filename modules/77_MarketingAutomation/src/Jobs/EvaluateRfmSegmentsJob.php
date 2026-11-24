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
use Modules\MarketingAutomation\Application\Commands\EvaluateRfmSegmentationAndBuildAudienceCommand;
use Modules\MarketingAutomation\Application\Commands\EvaluateRfmSegmentationAndBuildAudienceHandler;

/**
 * Hourly refresh of the segments flagged auto_refresh_hourly.
 */
final class EvaluateRfmSegmentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly string $tenantId) {}

    public function handle(EvaluateRfmSegmentationAndBuildAudienceHandler $handler, TenantContext $context): void
    {
        $tenant = Tenant::query()->findOrFail($this->tenantId);
        $context->runWithTenant($tenant, fn () => $handler->handle(new EvaluateRfmSegmentationAndBuildAudienceCommand($this->tenantId, null, true)));
    }
}
