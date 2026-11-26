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
    public function __construct(
        private readonly TenantSecurityPolicies $policies,
        private readonly BreachedPasswords $breached,
    ) {}

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

        foreach ($this->checklist($policy, $password, $user) as $rule) {
            if (! $rule['passed']) {
                $violations[] = $rule['message'];
            }
        }

        return $violations;
    }

    /**
     * Every rule of the policy with whether the password meets it: `min_length`, `mixed_case`, `numbers`, `symbols`
     * (the complexity ones only when the policy asks for them), `reused` (only with a user and a history rule) and
     * `breached` (only with the compromised-password check on; it is asked last and only when the other rules pass, so a
     * password that is already refused never leaves the server). A failed `breached` lookup counts as passed: the check
     * fails open, see BreachedPasswords.
     *
     * @return list<array{code: string, passed: bool, message: string}>
     */
    public function checklist(EffectivePolicy $policy, string $password, ?User $user = null): array
    {
        $rules = [
            ['code' => 'min_length', 'passed' => mb_strlen($password) >= $policy->passwordMinLength, 'message' => __('enterprise_auth.password.too_short', ['min' => $policy->passwordMinLength])],
        ];

        if ($policy->passwordRequireMixedCase) {
            $rules[] = ['code' => 'mixed_case', 'passed' => preg_match('/\p{Ll}/u', $password) === 1 && preg_match('/\p{Lu}/u', $password) === 1, 'message' => __('enterprise_auth.password.mixed_case')];
        }

        if ($policy->passwordRequireNumbers) {
            $rules[] = ['code' => 'numbers', 'passed' => preg_match('/\p{N}/u', $password) === 1, 'message' => __('enterprise_auth.password.numbers')];
        }

        if ($policy->passwordRequireSymbols) {
            $rules[] = ['code' => 'symbols', 'passed' => preg_match('/[^\p{L}\p{N}]/u', $password) === 1, 'message' => __('enterprise_auth.password.symbols')];
        }

        if ($user !== null && $policy->passwordHistoryCount > 0) {
            $rules[] = ['code' => 'reused', 'passed' => ! $this->wasUsedRecently($user, $password, $policy->passwordHistoryCount), 'message' => __('enterprise_auth.password.reused', ['count' => $policy->passwordHistoryCount])];
        }

        if ($policy->passwordCheckBreached) {
            $othersPass = collect($rules)->every(static fn (array $rule): bool => $rule['passed']);
            $rules[] = ['code' => 'breached', 'passed' => ! ($othersPass && $password !== '' && $this->breached->isBreached($password)), 'message' => __('advanced_auth.password.breached')];
        }

        return $rules;
    }

    /**
     * An indicative strength from 0 (empty) to 4: one point for the length, mixed case, a number and a symbol, at least
     * 1 for any password, and at most 1 for a password found in a breach.
     */
    public function strength(EffectivePolicy $policy, string $password, bool $breached = false): int
    {
        if ($password === '') {
            return 0;
        }

        $points = (int) (mb_strlen($password) >= max(10, $policy->passwordMinLength))
            + (int) (preg_match('/\p{Ll}/u', $password) === 1 && preg_match('/\p{Lu}/u', $password) === 1)
            + (int) (preg_match('/\p{N}/u', $password) === 1)
            + (int) (preg_match('/[^\p{L}\p{N}]/u', $password) === 1);

        return $breached ? 1 : max(1, $points);
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
