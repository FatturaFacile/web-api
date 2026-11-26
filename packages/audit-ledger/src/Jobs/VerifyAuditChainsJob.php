<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Jobs;

use Enterprise\AuditLedger\Application\Commands\VerifyChainIntegrityCommand;
use Enterprise\AuditLedger\Application\Commands\VerifyChainIntegrityHandler;
use Enterprise\AuditLedger\Application\Exceptions\VerificationAlreadyRunning;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Enterprise\AuditLedger\Models\IntegrityVerificationRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * With a run id: executes that queued verification (on demand). Without: the nightly verification of every tenant chain,
 * one run per tenant, where the failure of a tenant is reported and never stops the next.
 */
final class VerifyAuditChainsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 3600;

    public function __construct(public ?string $runId = null) {}

    public function handle(VerifyChainIntegrityHandler $handler): void
    {
        if ($this->runId !== null) {
            $run = IntegrityVerificationRun::query()->find($this->runId);

            if ($run instanceof IntegrityVerificationRun && $run->status === IntegrityVerificationRun::STATUS_QUEUED) {
                $handler->execute($run);
            }

            return;
        }

        foreach (AuditLedgerEntry::query()->distinct()->pluck('tenant_id') as $tenantId) {
            try {
                $run = $handler->createRun(new VerifyChainIntegrityCommand((string) $tenantId, VerifyChainIntegrityCommand::TRIGGER_SCHEDULED));
                $handler->execute($run);
            } catch (VerificationAlreadyRunning) {
                continue;
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
