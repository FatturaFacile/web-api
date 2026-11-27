<?php

declare(strict_types=1);

namespace Modules\Observability\Application\Queries;

final readonly class NoisyNeighborsQuery
{
    public function __construct(public int $hours = 1, public int $limit = 20) {}
}
