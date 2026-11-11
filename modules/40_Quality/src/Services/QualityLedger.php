<?php

declare(strict_types=1);

namespace Modules\Quality\Services;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Domain events of the quality module on the immutable audit ledger (on top of the created, updated and deleted
 * entries every model writes). Payloads hold ids, statuses and counts, never free text of people.
 */
final readonly class QualityLedger
{
    public const string ModuleCode = 'MODULE_40_QUALITY';

    public function __construct(private AuditLedgerInterface $ledger) {}

    /**
     * @param  array<string, mixed>  $details
     */
    public function record(string $action, Model $model, array $details = []): void
    {
        $this->ledger->record($action, $model, null, $details === [] ? null : $details, self::ModuleCode);
    }
}
