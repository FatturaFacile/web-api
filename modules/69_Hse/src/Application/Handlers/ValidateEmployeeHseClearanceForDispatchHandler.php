<?php

declare(strict_types=1);

namespace Modules\Hse\Application\Handlers;

use Modules\Hse\Application\Queries\ValidateEmployeeHseClearanceForDispatchQuery;
use Modules\Hse\Domain\Contracts\HseClearanceGatekeeperInterface;
use Modules\Hse\Domain\ValueObjects\ClearanceRequest;
use Modules\Hse\Domain\ValueObjects\ClearanceResult;

final class ValidateEmployeeHseClearanceForDispatchHandler
{
    public function __construct(private readonly HseClearanceGatekeeperInterface $gatekeeper) {}

    public function handle(ValidateEmployeeHseClearanceForDispatchQuery $query): ClearanceResult
    {
        return $this->gatekeeper->evaluate(new ClearanceRequest(
            $query->tenantId, $query->employeeId, $query->targetContextType, $query->targetId, $query->scheduledDateTime,
        ));
    }
}
