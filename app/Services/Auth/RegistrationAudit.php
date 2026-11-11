<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;

/**
 * Registration, password and second factor events on the audit ledger. They identify users by id only: the ledger is immutable, so it must not
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

    public const PASSWORD_RESET_REQUESTED = 'PASSWORD_RESET_REQUESTED';

    public const PASSWORD_RESET_SENT = 'PASSWORD_RESET_SENT';

    public const PASSWORD_RESET_RESENT = 'PASSWORD_RESET_RESENT';

    public const PASSWORD_RESET_LINK_OPENED = 'PASSWORD_RESET_LINK_OPENED';

    public const PASSWORD_RESET_COMPLETED = 'PASSWORD_RESET_COMPLETED';

    public const PASSWORD_RESET_TOKEN_INVALID = 'PASSWORD_RESET_TOKEN_INVALID';

    public const PASSWORD_RESET_TOKEN_EXPIRED = 'PASSWORD_RESET_TOKEN_EXPIRED';

    public const PASSWORD_RESET_ATTEMPTS_EXCEEDED = 'PASSWORD_RESET_ATTEMPTS_EXCEEDED';

    public const PASSWORD_RESET_SESSIONS_REVOKED = 'PASSWORD_RESET_SESSIONS_REVOKED';

    public const PASSWORD_CHANGE_NOTIFIED = 'PASSWORD_CHANGE_NOTIFIED';

    public const PASSWORD_CHANGED = 'PASSWORD_CHANGED';

    public const PASSWORD_CHANGE_FAILED = 'PASSWORD_CHANGE_FAILED';

    public const PASSWORD_CHANGE_ATTEMPTS_EXCEEDED = 'PASSWORD_CHANGE_ATTEMPTS_EXCEEDED';

    public const TWO_FACTOR_SETUP_STARTED = 'TWO_FACTOR_SETUP_STARTED';

    public const TWO_FACTOR_ENABLED = 'TWO_FACTOR_ENABLED';

    public const TWO_FACTOR_DISABLED = 'TWO_FACTOR_DISABLED';

    public const TWO_FACTOR_RESET_BY_ADMIN = 'TWO_FACTOR_RESET_BY_ADMIN';

    public const TWO_FACTOR_RECOVERY_CODES_REGENERATED = 'TWO_FACTOR_RECOVERY_CODES_REGENERATED';

    public const TWO_FACTOR_RECOVERY_CODE_USED = 'TWO_FACTOR_RECOVERY_CODE_USED';

    public const TWO_FACTOR_CHALLENGE_FAILED = 'TWO_FACTOR_CHALLENGE_FAILED';

    public const TWO_FACTOR_REQUIREMENT_CHANGED = 'TWO_FACTOR_REQUIREMENT_CHANGED';

    public const SESSION_REVOKED = 'SESSION_REVOKED';

    public const OTHER_SESSIONS_REVOKED = 'OTHER_SESSIONS_REVOKED';

    public const LOGIN_LOCKED = 'LOGIN_LOCKED';

    public const PASSWORD_EXPIRED_LOGIN_DENIED = 'PASSWORD_EXPIRED_LOGIN_DENIED';

    public const PASSWORD_LOGIN_DENIED_SSO_ONLY = 'PASSWORD_LOGIN_DENIED_SSO_ONLY';

    public const SECURITY_POLICY_CHANGED = 'SECURITY_POLICY_CHANGED';

    public const SESSION_IDLE_EXPIRED = 'SESSION_IDLE_EXPIRED';

    public const SESSION_LIMIT_REVOKED = 'SESSION_LIMIT_REVOKED';

    public const SSO_PROVIDER_SAVED = 'SSO_PROVIDER_SAVED';

    public const SSO_PROVIDER_REMOVED = 'SSO_PROVIDER_REMOVED';

    public const SSO_LOGOUT = 'SSO_LOGOUT';

    public const SSO_LOGIN_STARTED = 'SSO_LOGIN_STARTED';

    public const SSO_LOGIN_SUCCEEDED = 'SSO_LOGIN_SUCCEEDED';

    public const SSO_LOGIN_FAILED = 'SSO_LOGIN_FAILED';

    public const SSO_USER_PROVISIONED = 'SSO_USER_PROVISIONED';

    public const SSO_IDENTITY_LINKED = 'SSO_IDENTITY_LINKED';

    public const SCIM_TOKEN_ROTATED = 'SCIM_TOKEN_ROTATED';

    public const SCIM_TOKEN_REVOKED = 'SCIM_TOKEN_REVOKED';

    public const SCIM_USER_CREATED = 'SCIM_USER_CREATED';

    public const SCIM_USER_UPDATED = 'SCIM_USER_UPDATED';

    public const SCIM_USER_DEACTIVATED = 'SCIM_USER_DEACTIVATED';

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
