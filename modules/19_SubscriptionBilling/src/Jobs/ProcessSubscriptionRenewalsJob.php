<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\SubscriptionBilling\Contracts\RenewalServiceInterface;

final class ProcessSubscriptionRenewalsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $tenantId,
        public int $lookaheadDays = 30
    ) {}

    public function handle(RenewalServiceInterface $renewalService): void
    {
        $renewalService->processExpiringSubscriptions($this->tenantId, $this->lookaheadDays);
    }
}
