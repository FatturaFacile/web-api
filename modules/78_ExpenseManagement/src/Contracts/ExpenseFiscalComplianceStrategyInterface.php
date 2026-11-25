<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Contracts;

use Modules\ExpenseManagement\Domain\ValueObjects\LineAssessment;
use Modules\ExpenseManagement\Domain\ValueObjects\LineFacts;
use Modules\ExpenseManagement\Domain\ValueObjects\MileageRateRequest;
use Modules\ExpenseManagement\Domain\ValueObjects\PerDiemFacts;
use Modules\ExpenseManagement\Domain\ValueObjects\PerDiemResult;
use Modules\ExpenseManagement\Domain\ValueObjects\ResolvedMileageRate;

/**
 * The fiscal rules of a jurisdiction for expenses: VAT recoverability, traceable payments, mileage rates and per-diem
 * allowances. Resolved by the legal entity (CH, IT); a new jurisdiction registers its own implementation.
 */
interface ExpenseFiscalComplianceStrategyInterface
{
    /**
     * ISO 3166-1 alpha-2 code of the jurisdiction.
     */
    public function jurisdiction(): string;

    public function assessLine(LineFacts $facts): LineAssessment;

    public function resolveMileageRate(MileageRateRequest $request): ResolvedMileageRate;

    public function calculatePerDiem(PerDiemFacts $facts): PerDiemResult;

    /**
     * Codes of the fiscal notices (expenseManagement.fiscal.<code>) the interface shows for the jurisdiction.
     *
     * @return list<string>
     */
    public function noticeCodes(): array;
}
