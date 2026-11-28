<?php

declare(strict_types=1);

namespace Modules\TimeTravel\Application\Queries;

use Modules\TimeTravel\Domain\Enums\QueryDomain;
use Modules\TimeTravel\Domain\ValueObjects\BiTemporalCoordinate;

final readonly class ReconstructStateAtBiTemporalCoordinateQuery
{
    /**
     * @param  array<string, mixed>  $filters  account_id, ledger_type, partner_type, partner_id, warehouse_id, item_id
     */
    public function __construct(
        public string $tenantId,
        public QueryDomain $domain,
        public BiTemporalCoordinate $coordinate,
        public array $filters = [],
        public bool $useCheckpoints = true,
    ) {}
}
