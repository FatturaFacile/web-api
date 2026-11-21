<?php

declare(strict_types=1);

namespace Modules\AnalyticsDss\Application\Queries;

use Modules\AnalyticsDss\Domain\ValueObjects\DssFilter;

/**
 * Read-only DSS query; the handler of the same name answers it.
 */
final readonly class GetExecutiveDssOverviewQuery
{
    public function __construct(public DssFilter $filter) {}
}
