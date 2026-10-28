<?php

declare(strict_types=1);

namespace Modules\Invoicing\Actions;

use Modules\Invoicing\Contracts\CustomerCreditServiceInterface;
use Modules\Invoicing\Models\SalesOrderHold;

final class ReleaseSalesOrderHoldAction
{
    public function __construct(
        private readonly CustomerCreditServiceInterface $creditService
    ) {}

    public function execute(SalesOrderHold $hold, string $userId, ?string $notes = null): SalesOrderHold
    {
        return $this->creditService->releaseHold($hold, $userId, $notes);
    }
}
