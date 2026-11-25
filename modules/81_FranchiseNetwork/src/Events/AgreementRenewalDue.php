<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * An active affiliation agreement entered its renewal notice window (raised once per agreement).
 */
final class AgreementRenewalDue
{
    use Dispatchable;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $agreementId,
        public readonly string $storeId,
        public readonly string $endDate,
    ) {}
}
