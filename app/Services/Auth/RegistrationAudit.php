<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;

/**
 * Registration events on the audit ledger. They identify users by id only: the ledger is immutable, so it must not
 * accumulate email addresses, and tokens never reach it. Events without a tenant go on the ledger's system chain.
 */
final class RegistrationAudit
{
    public const USER_REGISTERED = 'USER_REGISTERED';

    public const VERIFICATION_SENT = 'EMAIL_VERIFICATION_SENT';

    public const VERIFICATION_RESENT = 'EMAIL_VERIFICATION_RESENT';

    public const EMAIL_VERIFIED = 'EMAIL_VERIFIED';

    public const TOKEN_INVALID = 'EMAIL_VERIFICATION_TOKEN_INVALID';

    public const TOKEN_EXPIRED = 'EMAIL_VERIFICATION_TOKEN_EXPIRED';

    public const ACCESS_APPROVED = 'TENANT_ACCESS_APPROVED';

    public const ACCESS_MOVED = 'TENANT_ACCESS_MOVED';

    public const ACCESS_REJECTED = 'TENANT_ACCESS_REJECTED';

    public function __construct(private readonly AuditLedgerInterface $ledger) {}

    /**
     * @param  array<string, mixed>  $details
     */
    public function record(string $action, ?string $userId = null, array $details = [], ?string $tenantId = null): void
    {
        $data = [
            'module_code' => 'AUTH',
            'action' => $action,
            'model_class' => User::class,
            'actor_user_id' => auth()->id() ?? $userId,
            'payload_after' => $details === [] ? null : $details,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ];

        if ($userId !== null) {
            $data['model_id'] = $userId;
        }

        if ($tenantId !== null) {
            $data['tenant_id'] = $tenantId;
            $data['company_id'] = $tenantId;
        }

        $this->ledger->recordPayload($data);
    }
}
