<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Actions;

use Enterprise\AuditLedger\Application\Commands\CreateCheckpointCommand;
use Enterprise\AuditLedger\Application\Commands\CreateCheckpointHandler;
use Enterprise\AuditLedger\Models\AuditCheckpoint;
use Modules\GeneralLedger\Contracts\PeriodClosingServiceInterface;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Throwable;

final readonly class CloseFiscalPeriodAction
{
    public function __construct(
        private PeriodClosingServiceInterface $closingService
    ) {}

    public function execute(string $fiscalPeriodId, string $closedBy): FiscalPeriod
    {
        $period = $this->closingService->closePeriod($fiscalPeriodId, $closedBy);

        $this->checkpointAuditChain($period);

        return $period;
    }

    /**
     * The close of an accounting period pins the audit chain of the tenant (Mod 005): the checkpoint is the proof of what
     * the ledger held when the period was closed. A failure to write it is reported and never undoes the close.
     */
    private function checkpointAuditChain(FiscalPeriod $period): void
    {
        $tenantId = $period->getAttribute('tenant_id');

        if ($tenantId === null) {
            return;
        }

        try {
            app(CreateCheckpointHandler::class)->handle(new CreateCheckpointCommand((string) $tenantId, AuditCheckpoint::TYPE_PERIOD, (string) $period->getKey()));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
