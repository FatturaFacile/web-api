<?php

declare(strict_types=1);

namespace Modules\Workflow\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Workflow\Services\WorkflowLifecycleService;
use Modules\Workflow\Services\WorkflowTimeoutProcessor;

/**
 * Every five minutes, per tenant: steps past their deadline get their automatic decision or their timeout, and the steps
 * of approvers who left the company are handed over (to the delegate, the manager or the administrators).
 */
final class ProcessWorkflowTimeoutsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Run after the surrounding transaction commits.
     */
    public function __construct()
    {
        $this->afterCommit();
    }

    /**
     * Process the due steps and the orphaned ones.
     */
    public function handle(WorkflowTimeoutProcessor $processor, WorkflowLifecycleService $lifecycle): void
    {
        $processor->deadlines();
        $lifecycle->reassignOrphanedSteps();
    }
}
