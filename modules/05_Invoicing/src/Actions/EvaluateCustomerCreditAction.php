<?php

declare(strict_types=1);

namespace Modules\Invoicing\Actions;

use Modules\Invoicing\Contracts\CustomerCreditServiceInterface;
use Modules\Invoicing\Models\CustomerCreditProfile;

final class EvaluateCustomerCreditAction
{
    public function __construct(
        private readonly CustomerCreditServiceInterface $creditService
    ) {}

    public function execute(string $customerId, string $tenantId): CustomerCreditProfile
    {
        return $this->creditService->evaluateCredit($customerId, $tenantId);
    }
}
