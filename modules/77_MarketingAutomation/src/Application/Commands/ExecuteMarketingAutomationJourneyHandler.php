<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Application\Commands;

use Illuminate\Support\Carbon;
use Modules\MarketingAutomation\Domain\Enums\CampaignStatus;
use Modules\MarketingAutomation\Models\Campaign;
use Modules\MarketingAutomation\Services\Journey\JourneyRunnerService;

final readonly class ExecuteMarketingAutomationJourneyHandler
{
    public function __construct(private JourneyRunnerService $runner) {}

    /**
     * @return array{enrolled: int, steps_executed: int}
     */
    public function handle(ExecuteMarketingAutomationJourneyCommand $command, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $enrolled = 0;
        $executed = 0;

        $campaigns = Campaign::query()
            ->where('tenant_id', $command->tenantId)
            ->where('status', CampaignStatus::Active->value)
            ->when($command->campaignId !== null, static fn ($q) => $q->whereKey($command->campaignId))
            ->get();

        foreach ($campaigns as $campaign) {
            $enrolled += $this->runner->enroll($campaign, $now);
            $executed += $this->runner->process($campaign, $now);
        }

        return ['enrolled' => $enrolled, 'steps_executed' => $executed];
    }
}
