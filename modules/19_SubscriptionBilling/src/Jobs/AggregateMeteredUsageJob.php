<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Jobs;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\SubscriptionBilling\Contracts\UsageBillingServiceInterface;
use Modules\SubscriptionBilling\Models\SubscriptionItem;

final class AggregateMeteredUsageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $tenantId,
        public ?string $periodStartDate = null,
        public ?string $periodEndDate = null
    ) {}

    public function handle(UsageBillingServiceInterface $usageService): void
    {
        $start = $this->periodStartDate ? Carbon::parse($this->periodStartDate) : now()->startOfMonth();
        $end = $this->periodEndDate ? Carbon::parse($this->periodEndDate) : now()->endOfMonth();

        $meteredItems = SubscriptionItem::where('tenant_id', $this->tenantId)
            ->whereNotNull('metric_id')
            ->get();

        foreach ($meteredItems as $item) {
            $usageService->aggregateUsage($item->id, $start, $end);
        }
    }
}
