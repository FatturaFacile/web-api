<?php

declare(strict_types=1);

namespace Modules\Projects\Commands;

/**
 * Ingests the approved timesheets, expenses, vendor costs, warehouse issues and open purchase orders of a project into
 * its cost ledger (idempotent).
 */
final readonly class SyncProjectCostLedgerCommand
{
    public function __construct(public string $projectId) {}
}
