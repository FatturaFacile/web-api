<?php

declare(strict_types=1);

namespace Modules\Observability\Application\Queries;

final readonly class TopSlowQueriesQuery
{
    public function __construct(public int $hours = 24, public int $limit = 20) {}
}
