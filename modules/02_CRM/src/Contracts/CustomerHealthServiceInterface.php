<?php

declare(strict_types=1);

namespace Modules\CRM\Contracts;

use Modules\CRM\DTOs\EvaluateHealthDTO;
use Modules\CRM\Models\Account;
use Modules\CRM\Models\CustomerHealthProfile;

interface CustomerHealthServiceInterface
{
    public function evaluateHealth(Account $account, ?EvaluateHealthDTO $dto = null): CustomerHealthProfile;

    public function recalculateTenantHealth(string $tenantId): int;
}
