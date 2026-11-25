<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Application\Queries;

use Modules\GdoTradeTerms\Services\MarginService;

final class GetNetNetMarginHandler
{
    public function __construct(private readonly MarginService $margin) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(GetNetNetMarginQuery $query): array
    {
        return $this->margin->netNet($query->tenantId, $query->from, $query->to, $query->contractId, $query->contactId, $query->groupBy);
    }
}
