<?php

declare(strict_types=1);

namespace Modules\Projects\Queries;

/**
 * The prepaid retainer / hour bank balance of a project and its latest movements (Mod 081).
 */
final readonly class GetPrepaidRetainerBalanceQuery
{
    public function __construct(
        public string $projectId,
    ) {}
}
