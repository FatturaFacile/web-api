<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Application\Queries;

/**
 * Pages through the runs of a tenant, newest first.
 */
final readonly class ListMrpRunsQuery
{
    public function __construct(
        public string $tenantId,
        public int $page = 1,
        public int $perPage = 25,
        public ?string $status = null,
    ) {}
}
