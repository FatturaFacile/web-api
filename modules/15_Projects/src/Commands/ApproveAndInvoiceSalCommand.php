<?php

declare(strict_types=1);

namespace Modules\Projects\Commands;

/**
 * Records the customer approval of a SAL certificate and drafts its invoice (Mod 081).
 */
final readonly class ApproveAndInvoiceSalCommand
{
    public function __construct(
        public string $projectId,
        public string $salId,
        public string $taxRate,
        public ?string $approvedBy,
        public string $userId,
    ) {}
}
