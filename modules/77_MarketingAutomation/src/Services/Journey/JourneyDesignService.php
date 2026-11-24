<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Services\Journey;

use Illuminate\Support\Facades\DB;
use Modules\MarketingAutomation\Models\Campaign;
use Modules\MarketingAutomation\Models\JourneyStep;

/**
 * Persists the journey drawn in the visual builder: nodes (steps) and edges (next / true / false keys).
 */
final class JourneyDesignService
{
    /**
     * @param  list<array<string, mixed>>  $steps  validated by SaveJourneyRequest
     */
    public function replace(Campaign $campaign, array $steps): Campaign
    {
        DB::transaction(function () use ($campaign, $steps): void {
            JourneyStep::query()->where('campaign_id', $campaign->id)->forceDelete();

            foreach ($steps as $step) {
                JourneyStep::query()->create([...$step, 'tenant_id' => $campaign->tenant_id, 'campaign_id' => $campaign->id]);
            }
        });

        return $campaign;
    }
}
