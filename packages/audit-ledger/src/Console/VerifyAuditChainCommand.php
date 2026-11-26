<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Console;

use Enterprise\AuditLedger\Application\Commands\VerifyChainIntegrityCommand;
use Enterprise\AuditLedger\Application\Commands\VerifyChainIntegrityHandler;
use Enterprise\AuditLedger\Application\Exceptions\VerificationAlreadyRunning;
use Enterprise\AuditLedger\Jobs\VerifyAuditChainsJob;
use Illuminate\Console\Command;

/**
 * Verifies the audit chain of one tenant, or of every tenant (the nightly run, where the failure of one tenant never
 * stops the next). Exits with a failure when a chain is broken.
 */
final class VerifyAuditChainCommand extends Command
{
    protected $signature = 'audit-ledger:verify {--tenant= : Tenant id; every tenant when omitted}';

    protected $description = 'Verify the integrity of the audit hash chains and record the outcome';

    public function handle(VerifyChainIntegrityHandler $handler): int
    {
        $tenantId = $this->option('tenant');

        if (! is_string($tenantId) || $tenantId === '') {
            VerifyAuditChainsJob::dispatchSync();
            $this->info('All audit chains verified.');

            return self::SUCCESS;
        }

        try {
            $run = $handler->execute($handler->createRun(new VerifyChainIntegrityCommand($tenantId, VerifyChainIntegrityCommand::TRIGGER_MANUAL)));
        } catch (VerificationAlreadyRunning $e) {
            $this->warn($e->getMessage());

            return self::FAILURE;
        }

        $this->line("Outcome: {$run->outcome} ({$run->verified_entries} entries verified).");

        return $run->outcome === 'valid' ? self::SUCCESS : self::FAILURE;
    }
}
