<?php

declare(strict_types=1);

namespace Modules\Budgeting\Domain;

use Illuminate\Support\Carbon;

/**
 * What a document asks the budget for: a net amount in the currency of the budget, on an account, cost centre and
 * project, on a date (which decides the fiscal year), at a stage of the life of the document.
 */
final readonly class SpendRequest
{
    public function __construct(
        public string $tenantId,
        public string $sourceType,
        public ?string $sourceId,
        public ?string $sourceNumber,
        public string $stage,
        public string $amount,
        public ?string $accountId,
        public ?string $costCenterId,
        public ?string $projectId,
        public Carbon $date,
        public ?string $userId = null,
        public ?string $replacesRequisitionId = null,
    ) {}
}
