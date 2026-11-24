<?php

declare(strict_types=1);

namespace Modules\Workflow\Actions;

use Modules\Workflow\DTOs\StartWorkflowDTO;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Services\WorkflowEngineService;

final class StartWorkflowAction
{
    public function __construct(
        private readonly WorkflowEngineService $engine
    ) {}

    public function execute(StartWorkflowDTO $dto): WorkflowInstance
    {
        return $this->engine->start($dto);
    }
}
