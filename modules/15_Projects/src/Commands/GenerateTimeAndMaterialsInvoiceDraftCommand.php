<?php

declare(strict_types=1);

namespace Modules\Projects\Commands;

/**
 * Drafts the invoice of the approved billable hours, expenses and supplier bills of a project up to a date (Mod 081).
 */
final readonly class GenerateTimeAndMaterialsInvoiceDraftCommand
{
    public function __construct(
        public string $projectId,
        public string $upToDate,
        public string $taxRate,
        public string $userId,
    ) {}
}
