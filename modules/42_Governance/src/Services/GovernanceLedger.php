<?php

declare(strict_types=1);

namespace Modules\Governance\Services;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Domain events of the governance module on the immutable audit ledger (on top of the created, updated and deleted
 * entries every model writes). Payloads hold ids, statuses and counts, never free text.
 */
final readonly class GovernanceLedger
{
    public const string ModuleCode = 'MODULE_42_GOVERNANCE';

    public function __construct(private AuditLedgerInterface $ledger) {}

    /**
     * @param  array<string, mixed>  $details
     */
    public function record(string $action, Model $model, array $details = []): void
    {
        $this->ledger->record($action, $model, null, $details === [] ? null : $details, self::ModuleCode);
    }
}
