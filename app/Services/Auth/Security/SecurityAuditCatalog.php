<?php

declare(strict_types=1);

namespace App\Services\Auth\Security;

use App\Services\Auth\RegistrationAudit;
use Modules\Platform\Identity\Listeners\RecordSecurityEvent;
use ReflectionClass;
use ReflectionClassConstant;

/**
 * The authentication and security events the audit viewer lists: every action of RegistrationAudit (module `AUTH`)
 * and the security events of the IAM engine (`ACCESS.SECURITY.*`). The event type is the action as the ledger stores it
 * (upper case); its label is the API-served translation key `security.audit.event.<type in lower case, dots as
 * underscores>`.
 */
final class SecurityAuditCatalog
{
    public const AUTH_MODULE = 'AUTH';

    public const ACCESS_MODULE = 'ACCESS';

    public const IDENTITY_PREFIX = 'ACCESS.SECURITY.';

    /**
     * Prefix of the type, longest first, to its category. The first match wins.
     *
     * @var array<string, string>
     */
    private const CATEGORIES = [
        'SCIM_' => 'scim',
        'SSO_' => 'sso',
        'TWO_FACTOR_' => 'mfa',
        'TRUSTED_DEVICE' => 'mfa',
        'EMAIL_OTP_' => 'mfa',
        'WEBAUTHN_' => 'mfa',
        'PASSWORD_LOGIN_' => 'login',
        'LOGIN_LOCKED' => 'lockout',
        'LOGIN_UNLOCKED' => 'lockout',
        'PASSWORD_' => 'password',
        'LOGIN_' => 'login',
        'SESSION_' => 'session',
        'OTHER_SESSIONS_' => 'session',
        'SECURITY_POLICY_' => 'policy',
        'USER_REGISTERED' => 'registration',
        'EMAIL_VERIFICATION_' => 'registration',
        'EMAIL_VERIFIED' => 'registration',
        'TENANT_ACCESS_' => 'registration',
        'ACCESS.SECURITY.LOGIN_' => 'login',
        'ACCESS.SECURITY.ACCOUNT_' => 'lockout',
        'ACCESS.SECURITY.PASSKEY_' => 'passkey',
        'ACCESS.SECURITY.MFA_' => 'mfa',
        'ACCESS.SECURITY.SESSIONS_' => 'session',
        'ACCESS.SECURITY.ROLE_' => 'access',
        'ACCESS.SECURITY.IMPERSONATION_' => 'impersonation',
    ];

    /**
     * @return list<array{event_type: string, translation_key: string, category: string, status: string}>
     */
    public static function eventTypes(): array
    {
        $types = [];

        foreach (self::authActions() as $action) {
            $types[$action] = self::describe($action);
        }

        foreach (self::identityActions() as $action) {
            $types[$action] = self::describe($action);
        }

        ksort($types);

        return array_values($types);
    }

    /**
     * @return list<string>
     */
    public static function types(): array
    {
        return array_column(self::eventTypes(), 'event_type');
    }

    /**
     * The event types whose status is the given one (`success` or `failure`), by the failure convention of RegistrationAudit.
     *
     * @return list<string>
     */
    public static function typesWithStatus(string $status): array
    {
        return array_column(array_filter(self::eventTypes(), static fn (array $type): bool => $type['status'] === $status), 'event_type');
    }

    public static function translationKey(string $action): string
    {
        return 'security.audit.event.'.strtolower(str_replace('.', '_', $action));
    }

    public static function category(string $action): string
    {
        $action = strtoupper($action);

        foreach (self::CATEGORIES as $prefix => $category) {
            if (str_starts_with($action, $prefix)) {
                return $category;
            }
        }

        return 'other';
    }

    /**
     * @return array{event_type: string, translation_key: string, category: string, status: string}
     */
    private static function describe(string $action): array
    {
        return [
            'event_type' => $action,
            'translation_key' => self::translationKey($action),
            'category' => self::category($action),
            'status' => RegistrationAudit::statusOf($action),
        ];
    }

    /**
     * @return list<string>
     */
    private static function authActions(): array
    {
        $actions = [];

        foreach ((new ReflectionClass(RegistrationAudit::class))->getReflectionConstants(ReflectionClassConstant::IS_PUBLIC) as $constant) {
            if (is_string($constant->getValue())) {
                $actions[] = strtoupper($constant->getValue());
            }
        }

        return $actions;
    }

    /**
     * @return list<string>
     */
    private static function identityActions(): array
    {
        return array_map(static function (string $event): string {
            $short = substr((string) strrchr('\\'.$event, '\\'), 1);

            return self::IDENTITY_PREFIX.strtoupper((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $short));
        }, RecordSecurityEvent::events());
    }
}
