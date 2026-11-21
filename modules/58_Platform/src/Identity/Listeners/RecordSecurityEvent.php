<?php

declare(strict_types=1);

namespace Modules\Platform\Identity\Listeners;

use App\Models\User;
use App\Services\Tenancy\AccessAudit;
use Modules\Platform\Identity\Domain\Events\AccountLocked;
use Modules\Platform\Identity\Domain\Events\ImpersonationEnded;
use Modules\Platform\Identity\Domain\Events\ImpersonationStarted;
use Modules\Platform\Identity\Domain\Events\LoginFailed;
use Modules\Platform\Identity\Domain\Events\LoginSucceeded;
use Modules\Platform\Identity\Domain\Events\MfaDisabled;
use Modules\Platform\Identity\Domain\Events\MfaEnabled;
use Modules\Platform\Identity\Domain\Events\PasskeyRegistered;
use Modules\Platform\Identity\Domain\Events\PasskeyRevoked;
use Modules\Platform\Identity\Domain\Events\RoleAssigned;
use Modules\Platform\Identity\Domain\Events\RoleCreated;
use Modules\Platform\Identity\Domain\Events\RoleRevoked;
use Modules\Platform\Identity\Domain\Events\RoleUpdated;
use Modules\Platform\Identity\Domain\Events\SessionsRevoked;
use Modules\Platform\Services\TenantNotifier;
use Throwable;

/**
 * Every security event of the IAM engine goes to the immutable audit ledger (Mod 005, `access.security.<event>`, ids
 * only) and the ones that concern the person (new passkey, 2FA turned off, sessions revoked, role changed) also reach
 * them as a notification (Mod 008). A failing notification never fails the operation that raised the event.
 */
final readonly class RecordSecurityEvent
{
    /** @var array<class-string, string> events that notify the affected user: class => lang key under identity.notifications */
    private const array NOTIFIED = [
        PasskeyRegistered::class => 'passkey_registered',
        PasskeyRevoked::class => 'passkey_revoked',
        MfaDisabled::class => 'mfa_disabled',
        SessionsRevoked::class => 'sessions_revoked',
        RoleAssigned::class => 'role_changed',
        RoleRevoked::class => 'role_changed',
        AccountLocked::class => 'account_locked',
        ImpersonationStarted::class => 'impersonation_started',
    ];

    public function __construct(private AccessAudit $audit, private TenantNotifier $notifier) {}

    public function handle(object $event): void
    {
        if (! property_exists($event, 'tenantId') || ! property_exists($event, 'userId')) {
            return;
        }

        $name = $this->name($event);
        $detail = array_filter(get_object_vars($event), static fn (mixed $v, string $k): bool => ! in_array($k, ['tenantId', 'userId'], true) && $v !== null, ARRAY_FILTER_USE_BOTH);

        $this->audit->record('access.security.'.$name, (string) $event->tenantId, User::class, (string) $event->userId, null, $detail);

        $key = self::NOTIFIED[$event::class] ?? null;
        if ($key === null) {
            return;
        }

        try {
            $this->notifier->notify(
                (string) $event->tenantId,
                'system',
                (string) __("identity.notifications.{$key}.title"),
                (string) __("identity.notifications.{$key}.body"),
                '/profile/security',
                [(string) $event->userId],
            );
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @return list<class-string>
     */
    public static function events(): array
    {
        return [
            RoleCreated::class, RoleUpdated::class, RoleAssigned::class, RoleRevoked::class,
            PasskeyRegistered::class, PasskeyRevoked::class, MfaEnabled::class, MfaDisabled::class,
            LoginSucceeded::class, LoginFailed::class, AccountLocked::class, SessionsRevoked::class, ImpersonationStarted::class, ImpersonationEnded::class,
        ];
    }

    private function name(object $event): string
    {
        $short = substr(strrchr('\\'.$event::class, '\\') ?: '', 1);

        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $short));
    }
}
