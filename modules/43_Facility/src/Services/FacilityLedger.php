<?php

declare(strict_types=1);

namespace Modules\Facility\Services;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Domain events of the facility module on the immutable audit ledger (on top of the created, updated and deleted
 * entries every model writes). Payloads hold ids, statuses and counts, never free text of people.
 */
final readonly class FacilityLedger
{
    public const string ModuleCode = 'MODULE_43_FACILITY';

    public function __construct(private AuditLedgerInterface $ledger) {}

    /**
     * @param  array<string, mixed>  $details
     */
    public function record(string $action, Model $model, array $details = []): void
    {
        $this->ledger->record($action, $model, null, $details === [] ? null : $details, self::ModuleCode);
    }
}
