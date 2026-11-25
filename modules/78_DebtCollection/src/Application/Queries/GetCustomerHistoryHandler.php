<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Application\Queries;

use Illuminate\Support\Carbon;
use Modules\DebtCollection\Services\CustomerHistoryService;

final class GetCustomerHistoryHandler
{
    public function __construct(
        private CustomerHistoryService $history,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(GetCustomerHistoryQuery $query): array
    {
        return $this->history->build($query->customerId, Carbon::parse($query->asOf));
    }
}
