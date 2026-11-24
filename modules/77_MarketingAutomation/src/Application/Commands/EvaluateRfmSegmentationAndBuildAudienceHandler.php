<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Application\Commands;

use Illuminate\Support\Collection;
use Modules\MarketingAutomation\Models\Segment;
use Modules\MarketingAutomation\Services\SegmentEvaluationService;

final readonly class EvaluateRfmSegmentationAndBuildAudienceHandler
{
    public function __construct(private SegmentEvaluationService $evaluation) {}

    /**
     * @return Collection<int, Segment> the evaluated segments
     */
    public function handle(EvaluateRfmSegmentationAndBuildAudienceCommand $command): Collection
    {
        $segments = Segment::query()
            ->where('tenant_id', $command->tenantId)
            ->when($command->segmentId !== null, static fn ($q) => $q->whereKey($command->segmentId))
            ->when($command->onlyAutoRefresh, static fn ($q) => $q->where('auto_refresh_hourly', true))
            ->get();

        return $segments->map(fn (Segment $segment): Segment => $this->evaluation->evaluate($segment));
    }
}
