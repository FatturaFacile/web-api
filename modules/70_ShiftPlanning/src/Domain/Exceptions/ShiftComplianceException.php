<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Domain\Exceptions;

use Modules\ShiftPlanning\Domain\ValueObjects\ComplianceIssue;

/**
 * The assignment breaks at least one blocking rule. The issues carry the stable rule codes for the client.
 */
final class ShiftComplianceException extends ShiftPlanningException
{
    /**
     * @param  list<ComplianceIssue>  $issues  every finding, blocking and warning
     */
    public function __construct(public readonly array $issues, string $message = 'The shift assignment breaks a blocking compliance rule.')
    {
        parent::__construct($message);
    }

    /**
     * @return list<ComplianceIssue>
     */
    public function blockingIssues(): array
    {
        return array_values(array_filter($this->issues, static fn (ComplianceIssue $issue): bool => $issue->isBlocking()));
    }
}
