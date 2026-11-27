<?php

declare(strict_types=1);

namespace Modules\GreenCare\Application\Commands;

use Illuminate\Support\Carbon;

/**
 * Bills a seasonal contract: the next fixed installment and the winter standby fee, then the extra passages, the time and material hours,
 * the plants and the billable green waste of the period. `dryRun` returns the preview without an invoice.
 */
final readonly class GenerateGreenCareBillingCommand
{
    public function __construct(
        public string $contractId,
        public ?Carbon $periodStart = null,
        public ?Carbon $periodEnd = null,
        public bool $includeInstallment = true,
        public bool $includeSnowStandby = true,
        public bool $includeExtraPassages = true,
        public bool $includeTimeAndMaterial = true,
        public bool $includeWaste = true,
        public bool $dryRun = false,
        public ?string $userId = null,
    ) {}
}
