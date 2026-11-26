<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Application\Queries;

/**
 * The runs of the rate providers visible to a company: the scheduled ones (platform) and its own manual runs.
 */
final readonly class ListSyncRunsQuery
{
    public function __construct(public string $tenantId, public int $perPage = 25) {}
}
