<?php

declare(strict_types=1);

namespace Modules\SwissVat\Application\Commands;

use Modules\SwissVat\Models\SwissVatReturn;
use Modules\SwissVat\Services\SwissVatReturnWorkflowService;

final readonly class FinalizeSwissVatReturnHandler
{
    public function __construct(private SwissVatReturnWorkflowService $workflow) {}

    public function handle(FinalizeSwissVatReturnCommand $command): SwissVatReturn
    {
        return $this->workflow->finalize($command->tenantId, $command->returnId, $command->actorUserId);
    }
}
