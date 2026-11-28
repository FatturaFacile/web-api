<?php

declare(strict_types=1);

namespace Modules\TimeTravel\Application\Commands;

use Modules\TimeTravel\Domain\Enums\AggregateType;

final readonly class GenerateCheckpointsCommand
{
    public function __construct(public string $tenantId, public ?AggregateType $aggregateType = null) {}
}
