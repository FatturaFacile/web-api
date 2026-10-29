<?php

declare(strict_types=1);

namespace Modules\Treasury\Contracts;

use Modules\Treasury\Models\IntercompanyFunding;
use Modules\Treasury\Models\WorkingCapitalMetric;

interface LiquidityManagementServiceInterface
{
    /**
     * Execute automated cash pool concentration sweeping (ZBA / Target balance).
     *
     * @return array<int, IntercompanyFunding>
     */
    public function executeCashConcentration(string $cashPoolId, ?string $userId = null): array;

    /**
     * Compute enterprise working capital metrics (DSO, DPO, DIO, CCC, Quick Ratio).
     */
    public function calculateWorkingCapital(string $tenantId, ?string $metricDate = null, ?string $companyId = null): WorkingCapitalMetric;

    /**
     * Transfer funds between intercompany accounts with transfer-pricing interest tracking.
     */
    public function transferIntercompanyFunds(
        string $sourceBankAccountId,
        string $destinationBankAccountId,
        float $amount,
        string $transferDate,
        float $interestRate = 0.0250,
        ?string $cashPoolId = null,
        ?string $userId = null
    ): IntercompanyFunding;
}
