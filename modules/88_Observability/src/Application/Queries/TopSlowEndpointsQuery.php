<?php

declare(strict_types=1);

namespace Modules\Observability\Application\Queries;

final readonly class TopSlowEndpointsQuery
{
    public function __construct(public int $hours = 24, public int $limit = 20) {}
}
