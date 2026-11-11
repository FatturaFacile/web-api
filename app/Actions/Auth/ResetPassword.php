<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Enums\Auth\AuthProblem;
use App\Mail\PasswordChangedMail;
use App\Models\PasswordResetToken;
use App\Models\User;
use App\Services\Auth\DeviceDescription;
use App\Services\Auth\PasswordResetTokens;
use App\Services\Auth\RegistrationAudit;
use App\Services\Auth\Security\PasswordPolicy;
use App\Services\Auth\UserSessions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class ResetPassword
{
    public function __construct(
        private readonly CheckPasswordResetToken $check,
        private readonly PasswordResetTokens $tokens,
        private readonly RegistrationAudit $audit,
        private readonly PasswordPolicy $passwordPolicy,
        private readonly UserSessions $sessions,
    ) {}

    /**
     * Consume the token and sign the user out everywhere. Returns the problem when it cannot be used, null on success.
     */
    public function execute(string $rawToken, string $password): ?AuthProblem
    {
        $token = $this->check->execute($rawToken);

        if ($token instanceof AuthProblem) {
            return $token;
        }

        $user = $token->user;
        $this->passwordPolicy->assertAcceptable($user, $password);

        $hashedPassword = Hash::make($password);

        $consumed = DB::transaction(function () use ($token, $user, $hashedPassword): bool {
            $now = Carbon::now();

            // Atomic on the token: of two concurrent requests, only one updates the row.
            $updated = PasswordResetToken::query()
                ->whereKey($token->id)
                ->whereNull('used_at')
                ->where('expires_at', '>', $now)
                ->update(['used_at' => $now]);

            if ($updated === 0) {
                return false;
            }

            $this->passwordPolicy->remember($user, (string) $user->password);

            $user->forceFill([
                'password' => $hashedPassword,
                'remember_token' => Str::random(60),
                'password_changed_at' => $now,
                'last_password_reset_at' => $now,
                'password_reset_required' => false,
                'failed_login_attempts' => 0,
                'login_locked_until' => null,
            ])->save();

            $this->tokens->invalidateFor($user);
            $this->audit->record(RegistrationAudit::PASSWORD_RESET_COMPLETED, $user->id);

            $this->revokeAccess($user);
            $this->notify($user, $now);

            return true;
        });

        return $consumed ? null : $this->check->rejected(AuthProblem::PasswordResetTokenInvalid, $user->id);
    }

    private function revokeAccess(User $user): void
    {
        $revoked = $this->sessions->revokeAll($user);

        $this->audit->record(RegistrationAudit::PASSWORD_RESET_SESSIONS_REVOKED, $user->id, [
            'bearer_clients' => $revoked['tokens'],
            'browser_sessions' => $revoked['sessions'],
        ]);
    }

    private function notify(User $user, Carbon $changedAt): void
    {
        Mail::to($user->email)->locale($user->locale)->queue(new PasswordChangedMail(
            $user,
            $changedAt,
            request()->ip(),
            DeviceDescription::from(request()->userAgent()),
        ));

        $this->audit->record(RegistrationAudit::PASSWORD_CHANGE_NOTIFIED, $user->id);
    }
}
