<?php

declare(strict_types=1);

namespace Modules\Plm\CQRS\Queries;

/**
 * Multi-level BOM diff (Added=green, Removed=red, Modified=yellow) and Cost Roll-up comparison
 * between two revisions/BOMs or between an eBOM and mBOM.
 */
final readonly class GetBomRevisionDiffAndCostRollupQuery
{
    public function __construct(
        public string $tenantId,
        public ?string $fromRevisionId = null,
        public ?string $toRevisionId = null,
        public ?string $fromBomId = null,
        public ?string $toBomId = null,
        public ?string $revisionIdForEbomVsMbom = null,
    ) {}
}
