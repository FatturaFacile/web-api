<?php

declare(strict_types=1);

namespace Modules\Projects\Commands;

/**
 * Invoices a completed milestone of a billing contract, withholding its guarantee retention (Mod 081).
 */
final readonly class BillContractMilestoneCommand
{
    public function __construct(
        public string $projectId,
        public string $milestoneId,
        public string $taxRate,
        public string $userId,
    ) {}
}
