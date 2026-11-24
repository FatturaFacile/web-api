<?php

declare(strict_types=1);

namespace Modules\Workflow\Actions;

use Modules\Workflow\DTOs\RecordDecisionDTO;
use Modules\Workflow\Models\WorkflowInstanceStep;
use Modules\Workflow\Services\WorkflowEngineService;

final class RecordDecisionAction
{
    public function __construct(
        private readonly WorkflowEngineService $engine
    ) {}

    public function execute(RecordDecisionDTO $dto): WorkflowInstanceStep
    {
        return $this->engine->recordDecision($dto);
    }
}
