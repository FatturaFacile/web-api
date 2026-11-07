<?php

declare(strict_types=1);

namespace Modules\Workflow\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Workflow\Services\WorkflowWebhookDispatcherService;

/**
 * Sends the due webhook deliveries of the tenant in context. The deliveries are claimed first, so two workers running
 * this job at the same time never send the same delivery twice.
 */
final class DispatchOutboundWebhooksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Fifty deliveries with a five second HTTP timeout each stay well below this.
     */
    public int $timeout = 300;

    /**
     * A failed delivery is retried by the next scheduled run, with its own backoff.
     */
    public int $tries = 1;

    public function __construct()
    {
        $this->afterCommit();
    }

    public function handle(WorkflowWebhookDispatcherService $dispatcher): void
    {
        foreach ($dispatcher->claimDueAttempts(50) as $attempt) {
            $dispatcher->deliver($attempt);
        }
    }
}
