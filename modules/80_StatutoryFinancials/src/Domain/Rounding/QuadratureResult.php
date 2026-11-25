<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Domain\Rounding;

final readonly class QuadratureResult
{
    /**
     * @param  array<string, string>  $balanceSheetLeaves
     * @param  array<string, string>  $incomeStatementLeaves
     * @param  array<string, string>  $balanceSheetTotals
     * @param  array<string, string>  $incomeStatementTotals
     */
    public function __construct(
        public array $balanceSheetLeaves,
        public array $incomeStatementLeaves,
        public array $balanceSheetTotals,
        public array $incomeStatementTotals,
        public string $incomeStatementResidual,
        public string $balanceSheetResidual,
    ) {}
}
