<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Application\DTOs;

use Modules\ShiftPlanning\Domain\ValueObjects\ComplianceIssue;
use Modules\ShiftPlanning\Domain\ValueObjects\SurchargeBreakdown;

/**
 * The outcome of the real-time check of one shift for one employee.
 */
final readonly class ComplianceReport
{
    /**
     * @param  list<ComplianceIssue>  $issues
     * @param  string  $estimatedLaborCost  decimal with two places, in the currency of the contract
     */
    public function __construct(
        public array $issues,
        public SurchargeBreakdown $surcharges,
        public string $estimatedLaborCost,
    ) {}

    public function hasBlocking(): bool
    {
        return $this->blocking() !== [];
    }

    /**
     * @return list<ComplianceIssue>
     */
    public function blocking(): array
    {
        return array_values(array_filter($this->issues, static fn (ComplianceIssue $issue): bool => $issue->isBlocking()));
    }

    /**
     * @return list<ComplianceIssue>
     */
    public function warnings(): array
    {
        return array_values(array_filter($this->issues, static fn (ComplianceIssue $issue): bool => ! $issue->isBlocking()));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function issuesAsArray(): array
    {
        return array_map(static fn (ComplianceIssue $issue): array => $issue->toArray(), $this->issues);
    }
}
