<?php

declare(strict_types=1);

namespace Modules\Workflow\Listeners;

use Modules\Workflow\Events\WorkflowInstanceCompletedEvent;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Services\DocumentLockingBridgeService;

final class LockFiscalDocumentOnCompletionListener
{
    public function __construct(
        private readonly DocumentLockingBridgeService $lockingBridge
    ) {}

    public function handle(WorkflowInstanceCompletedEvent $event): void
    {
        $instance = WorkflowInstance::find($event->instanceId);
        if ($instance !== null && $event->status === 'approved') {
            $this->lockingBridge->executeFiscalLock($instance);
        }
    }
}
