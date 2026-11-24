<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Mail\PasswordResetAlertMail;
use App\Mail\PasswordResetMail;
use App\Models\User;
use App\Services\Auth\PasswordResetTokens;
use App\Services\Auth\RegistrationAudit;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Handles a request for a reset link. It runs after the response, whose content and timing are the same for every
 * address: only the mailbox owner learns what happened. Disabled and unverified accounts get no email.
 */
class RequestPasswordReset
{
    public function __construct(
        private readonly PasswordResetTokens $tokens,
        private readonly RegistrationAudit $audit,
    ) {}

    public function execute(string $email, bool $isResend = false): void
    {
        $user = User::query()->where('email', $email)->first();

        $this->audit->record(RegistrationAudit::PASSWORD_RESET_REQUESTED, $user?->id);

        if ($this->exceededLimit($email)) {
            $this->reportExceededLimit($email, $user);

            return;
        }

        if ($user === null || ! $user->is_active || $user->email_verified_at === null) {
            return;
        }

        DB::transaction(function () use ($user, $isResend): void {
            $issued = $this->tokens->issue($user);

            Mail::to($user->email)
                ->locale($user->locale)
                ->queue(new PasswordResetMail($user, $this->tokens->link($issued['token']), $issued['expires_at']));

            $this->audit->record(
                $isResend ? RegistrationAudit::PASSWORD_RESET_RESENT : RegistrationAudit::PASSWORD_RESET_SENT,
                $user->id,
            );
        });
    }

    /**
     * Silent on purpose: an error would let a caller flood a mailbox and watch for the difference.
     */
    private function exceededLimit(string $email): bool
    {
        $key = 'password-reset-email:'.hash('sha256', $email);

        if (RateLimiter::tooManyAttempts($key, (int) config('auth.password_reset.rate_limits.request_per_email_per_hour'))) {
            return true;
        }

        RateLimiter::hit($key, 3600);

        return false;
    }

    /**
     * At most once an hour for each address, so the limit itself cannot be used to flood the mailbox or the ledger.
     */
    private function reportExceededLimit(string $email, ?User $user): void
    {
        if (! Cache::add('password-reset-alert:'.hash('sha256', $email), true, 3600)) {
            return;
        }

        $this->audit->record(RegistrationAudit::PASSWORD_RESET_ATTEMPTS_EXCEEDED, $user?->id);

        if ($user !== null && $user->is_active && $user->email_verified_at !== null) {
            Mail::to($user->email)->locale($user->locale)->queue(new PasswordResetAlertMail($user));
        }
    }
}
