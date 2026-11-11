<?php

declare(strict_types=1);

namespace App\Services\Auth\Security;

use App\Models\PasswordHistory;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Checks a new password against the policy of the tenants of the user (length, complexity, history) and keeps the
 * history. Maximum age is checked at login (see passwordIsExpired).
 */
final class PasswordPolicy
{
    public function __construct(private readonly TenantSecurityPolicies $policies) {}

    /**
     * @throws ValidationException with the error on the `password` field
     */
    public function assertAcceptable(User $user, string $password): void
    {
        $violations = $this->violations($user, $password);

        if ($violations !== []) {
            throw ValidationException::withMessages(['password' => $violations]);
        }
    }

    /**
     * @return list<string> the messages of every rule the password breaks
     */
    public function violations(User $user, string $password): array
    {
        $policy = $this->policies->forUser($user);
        $violations = [];

        if (mb_strlen($password) < $policy->passwordMinLength) {
            $violations[] = __('enterprise_auth.password.too_short', ['min' => $policy->passwordMinLength]);
        }

        if ($policy->passwordRequireMixedCase && (preg_match('/\p{Ll}/u', $password) !== 1 || preg_match('/\p{Lu}/u', $password) !== 1)) {
            $violations[] = __('enterprise_auth.password.mixed_case');
        }

        if ($policy->passwordRequireNumbers && preg_match('/\p{N}/u', $password) !== 1) {
            $violations[] = __('enterprise_auth.password.numbers');
        }

        if ($policy->passwordRequireSymbols && preg_match('/[^\p{L}\p{N}]/u', $password) !== 1) {
            $violations[] = __('enterprise_auth.password.symbols');
        }

        if ($policy->passwordHistoryCount > 0 && $this->wasUsedRecently($user, $password, $policy->passwordHistoryCount)) {
            $violations[] = __('enterprise_auth.password.reused', ['count' => $policy->passwordHistoryCount]);
        }

        return $violations;
    }

    /**
     * Keeps the hash of the password the user is leaving, and trims the history to what any policy can still ask for.
     */
    public function remember(User $user, string $passwordHash): void
    {
        // The history belongs to the user, not to the tenant the request acts for: it is written as global data even
        // from a tenant context, which a tenant user could not do for any other global model.
        $context = app(TenantContext::class);
        $context->runWithoutTenant(static fn (): PasswordHistory => PasswordHistory::createGlobal(['user_id' => $user->getKey(), 'password_hash' => $passwordHash]));

        $stale = PasswordHistory::query()->where('user_id', $user->getKey())
            ->orderByDesc('created_at')->orderByDesc('id')->skip(24)->take(PHP_INT_MAX)->pluck('id');

        if ($stale->isNotEmpty()) {
            PasswordHistory::query()->whereIn('id', $stale)->delete();
        }
    }

    /**
     * Whether the password is older than the maximum age of the strictest policy of the user. A user who never changed it
     * counts from the creation of the account.
     */
    public function passwordIsExpired(User $user): bool
    {
        $maxAge = $this->policies->forUser($user)->passwordMaxAgeDays;

        if ($maxAge === null) {
            return false;
        }

        $since = $user->password_changed_at ?? $user->created_at;

        return $since !== null && $since->copy()->addDays($maxAge)->isPast();
    }

    private function wasUsedRecently(User $user, string $password, int $count): bool
    {
        if (Hash::check($password, (string) $user->password)) {
            return true;
        }

        $hashes = PasswordHistory::query()->where('user_id', $user->getKey())
            ->orderByDesc('created_at')->orderByDesc('id')->limit($count)->pluck('password_hash');

        foreach ($hashes as $hash) {
            if (Hash::check($password, (string) $hash)) {
                return true;
            }
        }

        return false;
    }
}
