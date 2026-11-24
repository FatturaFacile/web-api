<?php

declare(strict_types=1);

namespace Modules\SwissVat\Application\Commands;

use Modules\SwissVat\Models\SwissVatReturn;
use Modules\SwissVat\Services\SwissVatReturnWorkflowService;

final readonly class CalculateSwissVatReturnHandler
{
    public function __construct(private SwissVatReturnWorkflowService $workflow) {}

    public function handle(CalculateSwissVatReturnCommand $command): SwissVatReturn
    {
        return $this->workflow->calculate(
            $command->tenantId,
            $command->actorUserId,
            $command->fiscalYear,
            $command->periodType,
            $command->periodNumber,
            $command->overrides,
        );
    }
}
