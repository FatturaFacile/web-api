<?php

declare(strict_types=1);

namespace Modules\AnalyticsDss\Application\Queries;

use Modules\AnalyticsDss\Domain\ValueObjects\DssFilter;

/**
 * Read-only DSS query; the handler of the same name answers it.
 */
final readonly class GetDssDrillDownQuery
{
    public function __construct(public DssFilter $filter, public string $metric = 'revenue', public ?string $entityType = null, public ?string $entityId = null, public int $page = 1, public int $perPage = 50) {}
}
