<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\SubscriptionBilling\Services\IndexationService;

/**
 * Nightly: applies the inflation-index adjustments (ISTAT FOI / BFS LIK) that fall due, before the billing run, so the
 * cycle invoiced the same night already carries the indexed fee.
 */
final class ProcessSubscriptionIndexationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $tenantId,
        public ?string $asOf = null,
    ) {}

    public function handle(IndexationService $indexation): void
    {
        $indexation->processDue($this->tenantId, $this->asOf !== null ? CarbonImmutable::parse($this->asOf) : null);
    }
}
