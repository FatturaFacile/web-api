<?php

declare(strict_types=1);

namespace Modules\Workflow\Actions;

use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Services\DocumentLockingBridgeService;

final class ExecuteFiscalLockAction
{
    public function __construct(
        private readonly DocumentLockingBridgeService $lockingBridge
    ) {}

    public function execute(WorkflowInstance $instance): void
    {
        $this->lockingBridge->executeFiscalLock($instance);
    }
}
