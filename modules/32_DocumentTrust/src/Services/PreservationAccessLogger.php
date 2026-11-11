<?php

declare(strict_types=1);

namespace Modules\DocumentTrust\Services;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Illuminate\Database\Eloquent\Model;
use Modules\DocumentTrust\Models\PreservationAccessLog;
use Modules\DocumentTrust\Models\PreservationPackage;

/**
 * Records every access to the preservation archive in the append-only access log. Closing, export, verification and
 * settings changes also go to the audit ledger of the application.
 */
final readonly class PreservationAccessLogger
{
    private const string ModuleCode = 'MODULE_32_DOCUMENT_TRUST';

    /**
     * Actions that also reach the audit ledger.
     *
     * @var list<string>
     */
    private const array Ledgered = ['close', 'export', 'verify', 'settings', 'seal', 'hold_place', 'hold_release', 'disposal_propose', 'disposal_approve', 'disposal_reject', 'disposal_execute'];

    /**
     * Inject the audit ledger.
     */
    public function __construct(private AuditLedgerInterface $auditLedger) {}

    /**
     * @param  array<string, mixed>  $details
     */
    public function record(string $action, ?PreservationPackage $package, array $details = [], ?string $tenantId = null, ?string $userId = null, ?Model $subject = null): PreservationAccessLog
    {
        $log = PreservationAccessLog::create([
            'tenant_id' => $tenantId ?? $package?->tenant_id,
            'package_id' => $package?->id,
            'user_id' => $userId ?? auth()->id(),
            'action' => $action,
            'ip_address' => request()->ip(),
            'details' => $details,
            'created_at' => now(),
        ]);

        if (($subject ?? $package) !== null && in_array($action, self::Ledgered, true)) {
            $this->auditLedger->record('PRESERVATION_'.strtoupper($action), $subject ?? $package, null, $details, self::ModuleCode);
        }

        return $log;
    }
}
