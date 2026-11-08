<?php

declare(strict_types=1);

namespace Modules\Invoicing\Contracts;

use Modules\Invoicing\Models\CustomerCreditProfile;
use Modules\Invoicing\Models\SalesOrder;
use Modules\Invoicing\Models\SalesOrderHold;

interface CustomerCreditServiceInterface
{
    /**
     * Recomputes and stores the exposure of the customer (open receivables plus committed unbilled orders), optionally
     * leaving one order out.
     */
    public function evaluateCredit(string $customerId, string $tenantId, ?string $excludeOrderId = null): CustomerCreditProfile;

    /**
     * Credit check of an order before confirmation, following the credit check mode of the company. Amounts are decimal
     * strings.
     *
     * @return array{approved: bool, status: string, reason: ?string, exposure: string, limit: string}
     */
    public function checkOrderCredit(SalesOrder $order): array;

    public function placeHold(SalesOrder $order, string $holdType, string $reason, string $userId): SalesOrderHold;

    public function releaseHold(SalesOrderHold $hold, string $userId, ?string $notes = null): SalesOrderHold;

    public function updateCreditLimit(string $customerId, string|float $newLimit, string $userId, string $tenantId): CustomerCreditProfile;
}
