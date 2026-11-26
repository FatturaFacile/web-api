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

    public const PASSWORD_LOGIN_BREAK_GLASS = 'PASSWORD_LOGIN_BREAK_GLASS';

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

    public const SCIM_GROUP_CREATED = 'SCIM_GROUP_CREATED';

    public const SCIM_GROUP_UPDATED = 'SCIM_GROUP_UPDATED';

    public const SCIM_GROUP_DELETED = 'SCIM_GROUP_DELETED';

    public const SCIM_GROUP_MEMBERS_CHANGED = 'SCIM_GROUP_MEMBERS_CHANGED';

    public const SCIM_ROLE_APPLIED = 'SCIM_ROLE_APPLIED';

    public const SCIM_OPERATION_FAILED = 'SCIM_OPERATION_FAILED';

    public const SCIM_GROUP_MAPPING_SAVED = 'SCIM_GROUP_MAPPING_SAVED';

    public const SCIM_GROUP_MAPPING_REMOVED = 'SCIM_GROUP_MAPPING_REMOVED';

    public const LOGIN_SUCCEEDED = 'LOGIN_SUCCEEDED';

    public const LOGIN_FAILED = 'LOGIN_FAILED';

    public const LOGIN_UNLOCKED = 'LOGIN_UNLOCKED';

    public const TWO_FACTOR_CHALLENGE_PASSED = 'TWO_FACTOR_CHALLENGE_PASSED';

    public const TWO_FACTOR_EMAIL_ENABLED = 'TWO_FACTOR_EMAIL_ENABLED';

    public const TWO_FACTOR_EMAIL_DISABLED = 'TWO_FACTOR_EMAIL_DISABLED';

    public const TWO_FACTOR_EMAIL_CODE_SENT = 'TWO_FACTOR_EMAIL_CODE_SENT';

    public const TWO_FACTOR_WEBAUTHN_USED = 'TWO_FACTOR_WEBAUTHN_USED';

    public const TWO_FACTOR_SMS_ENABLED = 'TWO_FACTOR_SMS_ENABLED';

    public const TWO_FACTOR_SMS_DISABLED = 'TWO_FACTOR_SMS_DISABLED';

    public const TWO_FACTOR_SMS_CODE_SENT = 'TWO_FACTOR_SMS_CODE_SENT';

    public const TRUSTED_DEVICE_CREATED = 'TRUSTED_DEVICE_CREATED';

    public const TRUSTED_DEVICE_USED = 'TRUSTED_DEVICE_USED';

    public const TRUSTED_DEVICE_REVOKED = 'TRUSTED_DEVICE_REVOKED';

    public const TRUSTED_DEVICES_REVOKED = 'TRUSTED_DEVICES_REVOKED';

    public function __construct(private readonly AuditLedgerInterface $ledger) {}

    /**
     * Failure convention: an action whose name carries one of these words is a failed or refused attempt
     * (`SSO_LOGIN_FAILED`, `PASSWORD_LOGIN_DENIED_SSO_ONLY`, `LOGIN_LOCKED`, `PASSWORD_RESET_TOKEN_EXPIRED`...). Session
     * housekeeping (`SESSION_*`) is never a failure.
     */
    private const FAILURE_PATTERN = '/(?:^|_)(?:FAILED|DENIED|INVALID|EXPIRED|EXCEEDED|LOCKED|REJECTED)(?:_|$)/';

    /**
     * Standard keys of the `payload_after` details, read by the security audit viewer:
     * - `status`: `success` or `failure`; derived from the failure convention above unless the caller sets it;
     * - `auth_method`: `password`, `sso_saml`, `sso_oidc` (`sso` when the protocol is unknown), `passkey`; derived for
     *   login, password and SSO events unless the caller sets it;
     * - `mfa_method`: the second factor used (`totp`, `recovery_code`, `email_otp`, `webauthn`), set by the MFA code;
     * - `reason`: why an attempt failed, a short machine code;
     * - `user_id`: the user the event is about (set from $userId; the ledger row itself only has it as resource id).
     * The IP address, the user agent, the tenant and the user are columns of the ledger entry itself. Entries already on
     * the ledger are never rewritten: only new entries carry the standard keys.
     *
     * @param  array<string, mixed>  $details
     */
    public function record(string $action, ?string $userId = null, array $details = [], ?string $tenantId = null): void
    {
        $details = $this->withStandardKeys($action, $details, $userId);

        $data = [
            'module_code' => 'AUTH',
            'action' => $action,
            'model_class' => User::class,
            'actor_user_id' => auth()->id() ?? $userId,
            'payload_after' => $details,
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

    /**
     * Records an event of a user on the ledger of every tenant the user belongs to (a lockout or a failed sign-in concerns
     * all of them, and each tenant sees only its own ledger); a user without a tenant goes on the system chain.
     *
     * @param  array<string, mixed>  $details
     * @param  string|null  $onlyTenantId  restrict to this tenant when the user is a member of it
     */
    public function recordForUserTenants(string $action, User $user, array $details = [], ?string $onlyTenantId = null): void
    {
        $tenantIds = $user->tenants()->pluck('tenants.id')->map(static fn (mixed $id): string => (string) $id)->all();

        if ($onlyTenantId !== null && in_array($onlyTenantId, $tenantIds, true)) {
            $tenantIds = [$onlyTenantId];
        }

        if ($tenantIds === []) {
            $this->record($action, (string) $user->id, $details);

            return;
        }

        foreach ($tenantIds as $tenantId) {
            $this->record($action, (string) $user->id, $details, $tenantId);
        }
    }

    /**
     * `success` or `failure` for an action, by the failure convention of this class.
     */
    public static function statusOf(string $action): string
    {
        $action = strtoupper($action);

        return ! str_starts_with($action, 'SESSION_') && preg_match(self::FAILURE_PATTERN, $action) === 1 ? 'failure' : 'success';
    }

    /**
     * The authentication method an action implies, or null when it implies none.
     *
     * @param  array<string, mixed>  $details
     */
    public static function authMethodOf(string $action, array $details = []): ?string
    {
        $action = strtoupper($action);

        if (in_array($action, ['SSO_LOGIN_STARTED', 'SSO_LOGIN_SUCCEEDED', 'SSO_LOGIN_FAILED', 'SSO_LOGOUT', 'SSO_USER_PROVISIONED', 'SSO_IDENTITY_LINKED'], true)) {
            return match ($details['protocol'] ?? null) {
                'saml' => 'sso_saml',
                'oidc' => 'sso_oidc',
                default => 'sso',
            };
        }

        if ($action === self::LOGIN_UNLOCKED) {
            return null;
        }

        if (str_starts_with($action, 'PASSWORD_') || str_starts_with($action, 'LOGIN_') || $action === self::TWO_FACTOR_CHALLENGE_FAILED) {
            return 'password';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private function withStandardKeys(string $action, array $details, ?string $userId = null): array
    {
        if ($userId !== null) {
            $details['user_id'] ??= $userId;
        }

        $details['status'] ??= self::statusOf($action);

        if (! isset($details['auth_method']) && ($method = self::authMethodOf($action, $details)) !== null) {
            $details['auth_method'] = $method;
        }

        return $details;
    }
}
