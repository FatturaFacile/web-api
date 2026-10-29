<?php

declare(strict_types=1);

namespace Modules\Invoicing\Contracts;

use Modules\Invoicing\Models\CustomerCreditProfile;
use Modules\Invoicing\Models\SalesOrder;
use Modules\Invoicing\Models\SalesOrderHold;

interface CustomerCreditServiceInterface
{
    public function evaluateCredit(string $customerId, string $tenantId): CustomerCreditProfile;

    /**
     * @return array{approved: bool, status: string, reason: ?string, exposure: float, limit: float}
     */
    public function checkOrderCredit(SalesOrder $order): array;

    public function placeHold(SalesOrder $order, string $holdType, string $reason, string $userId): SalesOrderHold;

    public function releaseHold(SalesOrderHold $hold, string $userId, ?string $notes = null): SalesOrderHold;

    public function updateCreditLimit(string $customerId, float $newLimit, string $userId, string $tenantId): CustomerCreditProfile;
}
