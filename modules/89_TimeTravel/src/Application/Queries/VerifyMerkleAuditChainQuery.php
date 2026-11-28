<?php

declare(strict_types=1);

namespace Modules\TimeTravel\Application\Queries;

use Carbon\CarbonImmutable;

final readonly class VerifyMerkleAuditChainQuery
{
    public function __construct(public string $tenantId, public CarbonImmutable $from, public CarbonImmutable $to) {}
}
