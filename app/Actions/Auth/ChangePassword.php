<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Enums\Auth\AuthProblem;
use App\Mail\PasswordChangedMail;
use App\Models\User;
use App\Services\Auth\DeviceDescription;
use App\Services\Auth\PasswordResetTokens;
use App\Services\Auth\RegistrationAudit;
use App\Services\Auth\TwoFactorAuthentication;
use App\Services\Auth\UserSessions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Changes the password of the signed-in user. It asks the current password, and the second factor when the user has
 * one; wrong answers are counted per user and lock the change for a while. On success every session and token of the
 * user is revoked, the current one included, and an email reports the change.
 */
class ChangePassword
{
    public function __construct(
        private readonly TwoFactorAuthentication $twoFactor,
        private readonly UserSessions $sessions,
        private readonly PasswordResetTokens $resetTokens,
        private readonly RegistrationAudit $audit,
    ) {}

    /**
     * Returns the problem when the change is locked, null once the password is changed.
     *
     * @throws ValidationException when the current password or the second factor is missing or wrong
     */
    public function execute(User $user, string $currentPassword, string $newPassword, ?string $code, ?string $recoveryCode): ?AuthProblem
    {
        if ($this->isLocked($user)) {
            return AuthProblem::PasswordChangeLocked;
        }

        if (! Hash::check($currentPassword, (string) $user->password)) {
            $this->failed($user, 'current_password', __('password_change.errors.current_password_invalid'));
        }

        $usesTwoFactor = $this->twoFactor->isEnabled($user);

        if ($usesTwoFactor && blank($code) && blank($recoveryCode)) {
            throw ValidationException::withMessages(['code' => __('password_change.errors.code_required')]);
        }

        if ($usesTwoFactor) {
            $method = $this->twoFactor->verifyCode($user, $code, $recoveryCode);

            if ($method === null) {
                $this->failed($user, blank($code) ? 'recovery_code' : 'code', __('two_factor.errors.code_invalid_detail'));
            }

            if ($method === TwoFactorAuthentication::METHOD_RECOVERY_CODE) {
                $this->audit->record(RegistrationAudit::TWO_FACTOR_RECOVERY_CODE_USED, (string) $user->id, [
                    'remaining_recovery_codes' => $this->twoFactor->remainingRecoveryCodes($user),
                ]);
            }
        }

        $hashedPassword = Hash::make($newPassword);

        DB::transaction(function () use ($user, $hashedPassword): void {
            $now = Carbon::now();

            $user->forceFill([
                'password' => $hashedPassword,
                'remember_token' => Str::random(60),
                'password_changed_at' => $now,
            ])->save();

            // A reset link sent before the change must not bring the old account state back.
            $this->resetTokens->invalidateFor($user);

            $revoked = $this->sessions->revokeAll($user);
            $this->audit->record(RegistrationAudit::PASSWORD_CHANGED, (string) $user->id, [
                'bearer_clients' => $revoked['tokens'],
                'browser_sessions' => $revoked['sessions'],
            ]);

            Mail::to($user->email)->locale($user->locale)->queue(new PasswordChangedMail(
                $user,
                $now,
                request()->ip(),
                DeviceDescription::from(request()->userAgent()),
                afterRecovery: false,
            ));
            $this->audit->record(RegistrationAudit::PASSWORD_CHANGE_NOTIFIED, (string) $user->id);
        });

        RateLimiter::clear($this->failuresKey($user));

        return null;
    }

    /**
     * @throws ValidationException always, with the error on the field that was wrong
     */
    private function failed(User $user, string $field, string $message): never
    {
        RateLimiter::hit($this->failuresKey($user), (int) config('auth.password_change.lockout_minutes') * 60);
        $this->audit->record(RegistrationAudit::PASSWORD_CHANGE_FAILED, (string) $user->id, ['field' => $field]);

        if ($this->isLocked($user)) {
            $this->audit->record(RegistrationAudit::PASSWORD_CHANGE_ATTEMPTS_EXCEEDED, (string) $user->id);
        }

        throw ValidationException::withMessages([$field => $message]);
    }

    private function isLocked(User $user): bool
    {
        return RateLimiter::tooManyAttempts($this->failuresKey($user), (int) config('auth.password_change.max_failed_attempts'));
    }

    private function failuresKey(User $user): string
    {
        return 'password-change-failures:'.$user->id;
    }
}
