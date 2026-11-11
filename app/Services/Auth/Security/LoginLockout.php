<?php

declare(strict_types=1);

namespace App\Services\Auth\Security;

use App\Models\User;
use App\Services\Auth\RegistrationAudit;
use Illuminate\Support\Facades\DB;

/**
 * Locks an account after repeated wrong passwords, with the lockout rule of the strictest tenant policy of the user
 * (0 attempts = no lockout). Counters live on the user row and are updated atomically.
 */
final class LoginLockout
{
    public function __construct(
        private readonly TenantSecurityPolicies $policies,
        private readonly RegistrationAudit $audit,
    ) {}

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

        $locked = DB::transaction(function () use ($user, $policy): bool {
            /** @var User $fresh */
            $fresh = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $attempts = (int) $fresh->failed_login_attempts + 1;

            if ($attempts >= $policy->lockoutMaxAttempts) {
                $fresh->forceFill(['failed_login_attempts' => 0, 'login_locked_until' => now()->addMinutes($policy->lockoutMinutes)])->save();

                return true;
            }

            $fresh->forceFill(['failed_login_attempts' => $attempts])->save();

            return false;
        });

        if ($locked) {
            $this->audit->record(RegistrationAudit::LOGIN_LOCKED, (string) $user->id, ['minutes' => $policy->lockoutMinutes]);
        }
    }

    public function clear(User $user): void
    {
        if ($user->failed_login_attempts === 0 && $user->login_locked_until === null) {
            return;
        }

        $user->forceFill(['failed_login_attempts' => 0, 'login_locked_until' => null])->save();
    }
}
