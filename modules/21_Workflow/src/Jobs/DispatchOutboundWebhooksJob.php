<?php

declare(strict_types=1);

namespace Modules\Workflow\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Workflow\Models\WorkflowDeliveryAttempt;
use Modules\Workflow\Services\WorkflowWebhookDispatcherService;

final class DispatchOutboundWebhooksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->afterCommit();
    }

    public function handle(WorkflowWebhookDispatcherService $dispatcher): void
    {
        $pendingAttempts = WorkflowDeliveryAttempt::where('status', 'pending')
            ->where(function ($q): void {
                $q->whereNull('next_retry_at')
                    ->orWhere('next_retry_at', '<=', now());
            })
            ->with(['subscription', 'outboundEvent'])
            ->limit(50)
            ->get();

        foreach ($pendingAttempts as $attempt) {
            $dispatcher->deliver($attempt);
        }
    }
}
