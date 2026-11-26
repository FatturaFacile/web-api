<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Contracts;

use Enterprise\AuditLedger\Application\Commands\AppendAuditEntryCommand;
use Illuminate\Database\Eloquent\Model;

/**
 * The single way to write to the audit ledger. Every method appends in the transaction of the caller: a mutation that
 * cannot be audited fails (fail closed); only read-only events (views, downloads) are queued for retry when the ledger
 * is unavailable.
 */
interface AuditLedgerInterface
{
    /**
     * @param  array<string, mixed>|null  $payloadBefore
     * @param  array<string, mixed>|null  $payloadAfter
     */
    public function record(
        string $action,
        Model $model,
        ?array $payloadBefore = null,
        ?array $payloadAfter = null,
        ?string $moduleCode = null
    ): void;

    /**
     * @param  array<string, mixed>  $data
     */
    public function recordPayload(array $data): void;

    /**
     * Appends the entry described by the command.
     */
    public function append(AppendAuditEntryCommand $command): void;
}
