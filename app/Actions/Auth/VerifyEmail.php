<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Enums\Auth\AuthProblem;
use App\Models\User;
use App\Services\Auth\EmailVerificationTokens;
use App\Services\Auth\RegistrationAudit;
use Illuminate\Support\Carbon;

class VerifyEmail
{
    public function __construct(
        private readonly EmailVerificationTokens $tokens,
        private readonly RegistrationAudit $audit,
    ) {}

    /**
     * Consume the token. Returns the problem when it cannot be used, null when the address is now verified.
     */
    public function execute(string $token): ?AuthProblem
    {
        if (! $this->tokens->hasValidSignature($token)) {
            return $this->rejected(AuthProblem::VerificationTokenInvalid);
        }

        $hash = $this->tokens->hash($token);
        $user = User::query()->where('email_verification_token', $hash)->first();

        if ($user === null) {
            return $this->rejected(AuthProblem::VerificationTokenInvalid);
        }

        $now = Carbon::now();

        if ($user->email_verification_expires_at === null || $user->email_verification_expires_at->lte($now)) {
            return $this->rejected(AuthProblem::VerificationTokenExpired, $user->id);
        }

        // Atomic on the token: of two concurrent clicks, only one updates the row.
        $consumed = User::query()
            ->whereKey($user->id)
            ->where('email_verification_token', $hash)
            ->update([
                'email_verified_at' => $now,
                'email_verification_token' => null,
                'email_verification_expires_at' => null,
            ]);

        if ($consumed === 0) {
            return $this->rejected(AuthProblem::VerificationTokenInvalid);
        }

        $this->audit->record(RegistrationAudit::EMAIL_VERIFIED, $user->id);

        return null;
    }

    private function rejected(AuthProblem $problem, ?string $userId = null): AuthProblem
    {
        $this->audit->record(
            $problem === AuthProblem::VerificationTokenExpired ? RegistrationAudit::TOKEN_EXPIRED : RegistrationAudit::TOKEN_INVALID,
            $userId
        );

        return $problem;
    }
}
