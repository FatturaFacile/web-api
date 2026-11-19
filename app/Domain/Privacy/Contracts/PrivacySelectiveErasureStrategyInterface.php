<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Contracts;

use App\Domain\Privacy\Erasure\FiscalLockReport;

/**
 * Jurisdiction-specific rules of a selective erasure: the statutory refusal text shown to the data subject when fiscal
 * records block a full erasure, and the legal references behind it.
 */
interface PrivacySelectiveErasureStrategyInterface
{
    /** Jurisdiction code: CH, IT or CH+IT. */
    public function jurisdiction(): string;

    /**
     * Legal references that justify keeping the fiscal records.
     *
     * @return list<string>
     */
    public function legalReferences(): array;

    /**
     * Statutory explanation of the partial refusal, citing the retention expiry date of the report.
     */
    public function refusalJustification(FiscalLockReport $report): string;
}
