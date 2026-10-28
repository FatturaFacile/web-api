<?php

declare(strict_types=1);

namespace Modules\Procurement\Contracts;

use Modules\Procurement\DTOs\ExecuteThreeWayMatchDTO;
use Modules\Procurement\Models\ThreeWayMatchLog;

interface ThreeWayMatchingServiceInterface
{
    public function executeMatch(ExecuteThreeWayMatchDTO $dto): ThreeWayMatchLog;
}
