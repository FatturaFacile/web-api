<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Application\Commands;

/**
 * Aggregates the post-adjustment balances of the fiscal year (and of the comparative year), reclassifies the chart of accounts into the
 * official CEE/OIC or CO Art. 959a-959b / Swiss GAAP FER lines, places bifronte accounts by the sign of their balance and applies the
 * rounding quadrature of the chosen rounding mode.
 */
final readonly class GenerateStatutoryBalanceWithRoundingQuadratureCommand
{
    public function __construct(public string $dossierId, public ?string $userId = null) {}
}
