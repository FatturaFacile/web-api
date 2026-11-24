<?php

declare(strict_types=1);

namespace Modules\SupplierPortal\Services;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Domain events of the supplier portal module on the immutable audit ledger (on top of the created, updated and
 * deleted entries every model writes). Payloads hold ids, statuses and counts, never the free text of people nor full
 * bank account numbers.
 */
final readonly class SupplierPortalLedger
{
    public const string ModuleCode = 'MODULE_44_SUPPLIER_PORTAL';

    public function __construct(private AuditLedgerInterface $ledger) {}

    /**
     * @param  array<string, mixed>  $details
     */
    public function record(string $action, Model $model, array $details = []): void
    {
        $this->ledger->record($action, $model, null, $details === [] ? null : $details, self::ModuleCode);
    }
}
