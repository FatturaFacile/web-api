<?php

declare(strict_types=1);

namespace App\Services\Privacy;

use App\Models\Privacy\PrivacyDataSubjectRequest;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Illuminate\Support\Str;

/**
 * Privacy events on the immutable audit ledger. Only ids, statuses and counts are written: the ledger cannot be
 * edited, so it must never accumulate the personal data it is meant to protect.
 */
final readonly class PrivacyAudit
{
    public const string ModuleCode = 'PRIVACY';

    public function __construct(private AuditLedgerInterface $ledger) {}

    /**
     * @param  class-string  $resourceClass
     * @param  array<string, mixed>  $details
     */
    public function record(string $tenantId, string $action, string $resourceClass, string $resourceId, array $details = [], ?string $actorId = null): void
    {
        $this->ledger->recordPayload([
            'module_code' => self::ModuleCode,
            'action' => $action,
            'model_class' => $resourceClass,
            'model_id' => $resourceId,
            'tenant_id' => $tenantId,
            'company_id' => $tenantId,
            'actor_user_id' => $actorId !== null && Str::isUuid($actorId) ? $actorId : (auth()->id() !== null ? (string) auth()->id() : null),
            'payload_after' => $details === [] ? null : $details,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public function recordRequestEvent(PrivacyDataSubjectRequest $dsr, string $action, array $details = [], ?string $actorId = null): void
    {
        $this->record((string) $dsr->tenant_id, $action, PrivacyDataSubjectRequest::class, (string) $dsr->id, $details, $actorId);
    }
}
