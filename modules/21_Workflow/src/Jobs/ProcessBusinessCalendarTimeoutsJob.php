<?php

declare(strict_types=1);

namespace Modules\Workflow\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Workflow\Services\WorkflowEngineService;

final class ProcessBusinessCalendarTimeoutsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->afterCommit();
    }

    public function handle(WorkflowEngineService $engine): void
    {
        $engine->processTimeouts();
    }
}
