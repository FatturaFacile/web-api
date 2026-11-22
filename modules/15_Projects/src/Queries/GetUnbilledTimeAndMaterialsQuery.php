<?php

declare(strict_types=1);

namespace Modules\Projects\Queries;

/**
 * The time and materials of a project ready to be invoiced up to a date (Mod 081).
 */
final readonly class GetUnbilledTimeAndMaterialsQuery
{
    public function __construct(
        public string $projectId,
        public string $upToDate,
    ) {}
}
