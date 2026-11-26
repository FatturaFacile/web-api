<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Console;

use Enterprise\AuditLedger\Application\Commands\RotateAuditKeyCommand;
use Enterprise\AuditLedger\Application\Commands\RotateAuditKeyHandler;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Starts signing the audit chain of a tenant with a new key version (configure AUDIT_LEDGER_MASTER_KEY_V<n> first).
 */
final class RotateAuditKeyConsoleCommand extends Command
{
    protected $signature = 'audit-ledger:rotate-key {tenant : Tenant id} {version : The new key version}';

    protected $description = 'Rotate the signing key version of the audit chain of a tenant';

    public function handle(RotateAuditKeyHandler $handler): int
    {
        try {
            $handler->handle(new RotateAuditKeyCommand((string) $this->argument('tenant'), (int) $this->argument('version')));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Key rotated.');

        return self::SUCCESS;
    }
}
