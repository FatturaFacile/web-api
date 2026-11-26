<?php

declare(strict_types=1);

namespace App\Services\Auth\Security;

use App\Mail\AccountLockedMail;
use App\Mail\AccountUnlockedMail;
use App\Models\User;
use App\Services\Auth\DeviceDescription;
use App\Services\Auth\RegistrationAudit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Locks an account after repeated wrong passwords, with the lockout rule of the strictest tenant policy of the user
 * (0 attempts = no lockout). Counters live on the user row and are updated atomically.
 *
 * With the progressive lockout (`lockout_backoff`) every lockout that follows another one without a successful login in
 * between doubles the lock duration, up to `lockout_max_minutes`: `lockout_streak` counts the consecutive lockouts and
 * goes back to zero on a successful login or an administrator unlock. The user is told by email at every lockout.
 */
final class LoginLockout
{
    /**
     * The doubling stops here: 2^20 times the base is far beyond any cap.
     */
    private const MAX_DOUBLINGS = 20;

    public function __construct(
        private readonly TenantSecurityPolicies $policies,
        private readonly RegistrationAudit $audit,
    ) {}

    /**
     * The minutes of the lock for the n-th consecutive lockout (1-based): the base for the first, then doubled each time
     * when the progressive lockout is on, never above the cap (and never below the base).
     */
    public static function minutesFor(int $baseMinutes, int $streak, bool $backoff, int $maxMinutes): int
    {
        $base = max(1, $baseMinutes);

        if (! $backoff || $streak <= 1) {
            return $base;
        }

        $doubled = $base * (2 ** min($streak - 1, self::MAX_DOUBLINGS));

        return (int) min($doubled, max($base, $maxMinutes));
    }

    public function isLocked(User $user): bool
    {
        return $user->login_locked_until !== null && $user->login_locked_until->isFuture();
    }

    /**
     * Counts a wrong password; at the limit the account is locked and the counter starts again.
     */
    public function recordFailure(User $user): void
    {
        $policy = $this->policies->forUser($user);

        if ($policy->lockoutMaxAttempts === 0) {
            return;
        }

        // A password tried while the account is locked does not extend the lock.
        if ($this->isLocked($user)) {
            return;
        }

        /** @var array{minutes: int, streak: int, until: Carbon}|null $lock */
        $lock = DB::transaction(function () use ($user, $policy): ?array {
            /** @var User $fresh */
            $fresh = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $attempts = (int) $fresh->failed_login_attempts + 1;

            if ($attempts >= $policy->lockoutMaxAttempts) {
                $streak = (int) $fresh->lockout_streak + 1;
                $minutes = self::minutesFor($policy->lockoutMinutes, $streak, $policy->lockoutBackoff, $policy->lockoutMaxMinutes);
                $until = now()->addMinutes($minutes);
                $fresh->forceFill(['failed_login_attempts' => 0, 'login_locked_until' => $until, 'lockout_streak' => $streak])->save();

                return ['minutes' => $minutes, 'streak' => $streak, 'until' => $until];
            }

            $fresh->forceFill(['failed_login_attempts' => $attempts])->save();

            return null;
        });

        if ($lock === null) {
            return;
        }

        $this->audit->recordForUserTenants(RegistrationAudit::LOGIN_LOCKED, $user, [
            'minutes' => $lock['minutes'],
            'streak' => $lock['streak'],
            'locked_until' => $lock['until']->toIso8601String(),
            'reason' => 'too_many_failed_attempts',
        ]);

        $this->notifyLocked($user, $lock['minutes'], $lock['until']);
    }

    /**
     * A successful sign-in: the counters and the streak of consecutive lockouts start again.
     */
    public function clear(User $user): void
    {
        if ($user->failed_login_attempts === 0 && $user->login_locked_until === null && $user->lockout_streak === 0) {
            return;
        }

        $user->forceFill(['failed_login_attempts' => 0, 'login_locked_until' => null, 'lockout_streak' => 0])->save();
    }

    /**
     * An administrator lifts the lock: counters and streak are cleared, the event is audited and the user is told.
     */
    public function unlock(User $user, User $admin, string $tenantId): void
    {
        $wasLocked = $this->isLocked($user);

        $user->forceFill(['failed_login_attempts' => 0, 'login_locked_until' => null, 'lockout_streak' => 0])->save();

        $this->audit->record(RegistrationAudit::LOGIN_UNLOCKED, (string) $user->id, ['unlocked_by' => (string) $admin->id, 'was_locked' => $wasLocked], $tenantId);

        try {
            Mail::to($user->email)->locale($user->locale)->queue(new AccountUnlockedMail($user));
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function notifyLocked(User $user, int $minutes, Carbon $until): void
    {
        try {
            Mail::to($user->email)->locale($user->locale)->queue(new AccountLockedMail($user, $minutes, $until, request()->ip(), DeviceDescription::from(request()->userAgent())));
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
