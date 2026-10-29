<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Contracts;

use Modules\Manufacturing\DTOs\CreateRoutingDTO;
use Modules\Manufacturing\Models\MfgRouting;

interface RoutingServiceInterface
{
    public function createRouting(CreateRoutingDTO $dto): MfgRouting;

    public function calculateTotalStandardTimes(string $routingId, float $units): array;

    public function calculateStandardOperationsCost(string $routingId, float $units): array;
}
