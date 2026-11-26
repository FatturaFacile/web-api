<?php

declare(strict_types=1);

namespace Modules\Workflow\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Workflow\Services\WorkflowTimeoutProcessor;

/**
 * Reminders after X working hours and escalations after Y working hours, counted on the business calendar of the node
 * (weekends and the Swiss or Italian holidays of the calendar do not count). Each step is reminded and escalated once.
 */
final class SendApprovalRemindersJob implements ShouldQueue
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
     * Send the due reminders and escalations.
     */
    public function handle(WorkflowTimeoutProcessor $processor): void
    {
        $processor->reminders();
    }
}
