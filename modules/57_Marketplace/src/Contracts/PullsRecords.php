<?php

declare(strict_types=1);

namespace Modules\Marketplace\Contracts;

use Modules\Marketplace\Exceptions\ConnectorException;
use Modules\Marketplace\Support\ConnectorContext;

/**
 * A connector that can hand over the raw records of a resource, so the marketplace maps them through a
 * {@see RecordMapper} instead of only counting them. The pull is the only call to the external system and runs outside
 * any database transaction.
 */
interface PullsRecords
{
    /**
     * @param  string|null  $cursor  where the previous run stopped, null for the first run
     * @return list<array<string, mixed>>
     *
     * @throws ConnectorException when the external system refuses or fails
     */
    public function pull(ConnectorContext $context, string $resource, ?string $cursor): array;
}
