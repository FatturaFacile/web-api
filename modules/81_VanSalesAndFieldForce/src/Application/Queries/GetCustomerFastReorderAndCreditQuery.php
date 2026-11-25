<?php

declare(strict_types=1);

namespace Modules\VanSalesAndFieldForce\Application\Queries;

final readonly class GetCustomerFastReorderAndCreditQuery
{
    public function __construct(
        public string $tenantId,
        public string $contactId,
        public float $proposedSaleAmount = 0.0,
    ) {}
}
