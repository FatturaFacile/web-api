<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Listeners;

use App\Models\User;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Enterprise\AuditLedger\Support\LoginThrottle;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Str;

final class AuthAuditListener
{
    /**
     * Default retention window (days) for network metadata (anonymized IP and User-Agent) on auth audit entries
     * under GDPR Art. 5(1)(e) and Swiss nLPD. Because ip_address and user_agent are excluded from the SHA-256
     * record_hash calculation, expiring or erasing them preserves full cryptographic hash-chain integrity.
     */
    public const int DEFAULT_NETWORK_METADATA_RETENTION_DAYS = 90;

    public function __construct(
        private readonly AuditLedgerInterface $auditLedger,
        private readonly LoginThrottle $throttle,
    ) {}

    public function handleLogin(Login $event): void
    {
        if ($event->user instanceof User) {
            $tenantId = (string) ($event->user->current_tenant_id ?? $event->user->tenants->first()?->id ?? '00000000-0000-0000-0000-000000000000');
            $this->auditLedger->recordPayload([
                'tenant_id' => $tenantId,
                'company_id' => $tenantId,
                'module_code' => 'AUTH',
                'action' => 'LOGIN_SUCCESS',
                'model_class' => User::class,
                'model_id' => $event->user->id,
                'actor_user_id' => $event->user->id,
                'payload_after' => [
                    'guard' => $event->guard,
                    'retention_days' => self::networkRetentionDays(),
                ],
                'ip_address' => self::anonymizeIp(request()->ip()),
                'user_agent' => self::sanitizeUserAgent(request()->userAgent()),
            ]);
        }
    }

    public function handleLogout(Logout $event): void
    {
        if ($event->user instanceof User) {
            $tenantId = (string) ($event->user->current_tenant_id ?? $event->user->tenants->first()?->id ?? '00000000-0000-0000-0000-000000000000');
            $this->auditLedger->recordPayload([
                'tenant_id' => $tenantId,
                'company_id' => $tenantId,
                'module_code' => 'AUTH',
                'action' => 'LOGOUT',
                'model_class' => User::class,
                'model_id' => $event->user->id,
                'actor_user_id' => $event->user->id,
                'payload_after' => [
                    'guard' => $event->guard,
                    'retention_days' => self::networkRetentionDays(),
                ],
                'ip_address' => self::anonymizeIp(request()->ip()),
                'user_agent' => self::sanitizeUserAgent(request()->userAgent()),
            ]);
        }
    }

    public function handleFailed(Failed $event): void
    {
        $rawEmail = isset($event->credentials['email']) && is_string($event->credentials['email'])
            ? trim($event->credentials['email'])
            : '';
        $user = $event->user instanceof User
            ? $event->user
            : ($rawEmail !== '' ? User::where('email', $rawEmail)->first() : null);
        $tenantId = (string) ($user?->current_tenant_id ?? $user?->tenants->first()?->id ?? '00000000-0000-0000-0000-000000000000');

        $this->auditLedger->recordPayload([
            'tenant_id' => $tenantId,
            'company_id' => $tenantId,
            'module_code' => 'AUTH',
            'action' => 'LOGIN_FAILED',
            'model_class' => User::class,
            'model_id' => $user?->id ?? (string) Str::uuid7(),
            'actor_user_id' => $user?->id,
            'payload_after' => [
                'guard' => $event->guard,
                'account_matched' => $user !== null,
                'identifier_hash' => self::hashIdentifier($rawEmail),
                'retention_days' => self::networkRetentionDays(),
            ],
            'ip_address' => self::anonymizeIp(request()->ip()),
            'user_agent' => self::sanitizeUserAgent(request()->userAgent()),
        ]);

        // Repeated failures from one address: refuse that address for a while (EnforceAuditLoginThrottle) and say so on the chain.
        if ($this->throttle->hit(request()->ip())) {
            $this->auditLedger->recordPayload([
                'tenant_id' => $tenantId,
                'company_id' => $tenantId,
                'module_code' => 'AUTH',
                'action' => 'LOGIN_RATE_LIMITED',
                'category' => 'security',
                'model_class' => User::class,
                'model_id' => $user !== null ? (string) $user->id : (string) Str::uuid7(),
                'actor_user_id' => $user?->id,
                'payload_after' => [
                    'max_failures' => (int) config('audit_ledger.login_throttle.max_failures'),
                    'window_minutes' => (int) config('audit_ledger.login_throttle.window_minutes'),
                    'block_minutes' => (int) config('audit_ledger.login_throttle.block_minutes'),
                ],
                'ip_address' => self::anonymizeIp(request()->ip()),
                'user_agent' => self::sanitizeUserAgent(request()->userAgent()),
            ]);
        }
    }

    /**
     * Anonymizes IPv4 (masks last octet to .0) and IPv6 (masks interface identifier to /48) addresses for GDPR/nLPD compliance.
     */
    public static function anonymizeIp(?string $ip): ?string
    {
        if ($ip === null || trim($ip) === '') {
            return null;
        }

        $trimmed = trim($ip);

        if (filter_var($trimmed, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $packed = inet_pton($trimmed);

            return $packed !== false ? (inet_ntop($packed & "\xFF\xFF\xFF\x00") ?: null) : null;
        }

        if (filter_var($trimmed, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $packed = inet_pton($trimmed);
            $mask = str_repeat("\xFF", 6).str_repeat("\x00", 10);

            return $packed !== false ? (inet_ntop($packed & $mask) ?: null) : null;
        }

        return null;
    }

    /**
     * Strips query/token leakage and bounds user-agent length for safe storage.
     */
    public static function sanitizeUserAgent(?string $userAgent): ?string
    {
        if ($userAgent === null || trim($userAgent) === '') {
            return null;
        }

        $cleaned = preg_replace('/[\x00-\x1F\x7F]+/', ' ', trim($userAgent)) ?? '';

        return substr($cleaned, 0, 255);
    }

    /**
     * Computes a keyed, non-reversible 16-hex-character HMAC pseudonymous fingerprint of an identifier so brute-force
     * patterns against a single target can be correlated without storing raw email PII in the immutable payload.
     */
    public static function hashIdentifier(?string $identifier): ?string
    {
        if ($identifier === null || trim($identifier) === '') {
            return null;
        }

        $key = (string) (config('app.key') ?: 'fatturafacile-audit-hmac');

        return substr(hash_hmac('sha256', mb_strtolower(trim($identifier)), $key), 0, 16);
    }

    public static function networkRetentionDays(): int
    {
        $configured = (int) config('erp_foundation.audit.network_retention_days', self::DEFAULT_NETWORK_METADATA_RETENTION_DAYS);

        return $configured > 0 ? $configured : self::DEFAULT_NETWORK_METADATA_RETENTION_DAYS;
    }

    /**
     * Purges expired ip_address and user_agent metadata older than the retention window, or immediately for a
     * specific data subject (GDPR Art. 17 right-to-erasure), without altering hashed ledger fields.
     */
    public static function purgeNetworkMetadata(?string $userId = null, ?int $olderThanDays = null): int
    {
        $query = AuditLedgerEntry::query()
            ->where(function ($q): void {
                $q->whereNotNull('ip_address')->orWhereNotNull('user_agent');
            });

        if ($userId !== null) {
            $query->where(function ($q) use ($userId): void {
                $q->where('actor_user_id', $userId)->orWhere('resource_id', $userId);
            });
        }

        if ($olderThanDays !== null) {
            $query->where('event_timestamp', '<', now()->subDays($olderThanDays));
        }

        return $query->update([
            'ip_address' => null,
            'user_agent' => null,
        ]);
    }
}
