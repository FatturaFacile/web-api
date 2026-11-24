<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Enums\Auth\AuthProblem;
use App\Models\PasswordResetToken;
use App\Services\Auth\PasswordResetTokens;
use App\Services\Auth\RegistrationAudit;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Checks a reset token without consuming it, and locks out an address that keeps presenting bad ones.
 */
class CheckPasswordResetToken
{
    public function __construct(
        private readonly PasswordResetTokens $tokens,
        private readonly RegistrationAudit $audit,
    ) {}

    public function execute(string $rawToken): PasswordResetToken|AuthProblem
    {
        if (RateLimiter::tooManyAttempts($this->failuresKey(), $this->maxFailures())) {
            return AuthProblem::PasswordResetLocked;
        }

        $token = $this->tokens->find($rawToken);

        if ($token === null || $token->used_at !== null || $token->user === null || ! $token->user->is_active) {
            return $this->rejected(AuthProblem::PasswordResetTokenInvalid, $token?->user_id);
        }

        if ($token->expires_at->isPast()) {
            return $this->rejected(AuthProblem::PasswordResetTokenExpired, $token->user_id);
        }

        return $token;
    }

    public function rejected(AuthProblem $problem, ?string $userId = null): AuthProblem
    {
        RateLimiter::hit($this->failuresKey(), (int) config('auth.password_reset.lockout_minutes') * 60);

        $this->audit->record(
            $problem === AuthProblem::PasswordResetTokenExpired ? RegistrationAudit::PASSWORD_RESET_TOKEN_EXPIRED : RegistrationAudit::PASSWORD_RESET_TOKEN_INVALID,
            $userId,
        );

        if (RateLimiter::tooManyAttempts($this->failuresKey(), $this->maxFailures())) {
            $this->audit->record(RegistrationAudit::PASSWORD_RESET_ATTEMPTS_EXCEEDED, $userId);
        }

        return $problem;
    }

    private function failuresKey(): string
    {
        return 'password-reset-failures:'.request()->ip();
    }

    private function maxFailures(): int
    {
        return (int) config('auth.password_reset.max_failed_attempts');
    }
}
