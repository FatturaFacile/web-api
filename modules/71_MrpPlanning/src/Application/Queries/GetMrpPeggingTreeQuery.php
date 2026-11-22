<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Application\Queries;

/**
 * The multi-level pegging graph of a proposal: back to the root demand and forward through the components.
 */
final readonly class GetMrpPeggingTreeQuery
{
    public function __construct(
        public string $tenantId,
        public string $proposalId,
    ) {}
}
