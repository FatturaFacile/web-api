<?php

declare(strict_types=1);

namespace Modules\Projects\Services\Fiscal;

use Modules\Projects\Contracts\ProjectFiscalBillingStrategyInterface;
use Modules\Projects\Models\ProjectBillingContract;
use Modules\Projects\Support\Money;

/**
 * Rules the Italian and Swiss strategies share: the contract retention wins over the statutory one, the payable amount
 * and the provision of an expected loss.
 */
abstract class AbstractProjectFiscalBillingStrategy implements ProjectFiscalBillingStrategyInterface
{
    public function effectiveRetentionPercent(ProjectBillingContract $contract): string
    {
        if (Money::isPositive($contract->retention_guarantee_percent)) {
            return Money::of($contract->retention_guarantee_percent);
        }

        return $this->statutoryRetentionPercent($contract) ?? '0';
    }

    public function payableAmount(string $invoiceTotalWithVat, string $retentionWithheld): string
    {
        return Money::amount(Money::max(Money::sub($invoiceTotalWithVat, $retentionWithheld), 0));
    }

    public function expectedLossProvision(string $contractValue, string $estimatedTotalCost, string $completionRatio): string
    {
        $loss = Money::max(Money::sub($estimatedTotalCost, $contractValue), 0);

        return Money::amount(Money::mul($loss, Money::max(Money::sub(1, $completionRatio), 0)));
    }
}
