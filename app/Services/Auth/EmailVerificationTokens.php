<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Single-use tokens that confirm an email address.
 */
final class EmailVerificationTokens
{
    public function __construct(private readonly SignedTokens $signedTokens) {}

    /**
     * Give the user a new token, invalidating the previous one, and return its raw value for the link.
     */
    public function issue(User $user): string
    {
        $token = $this->signedTokens->generate();

        $user->forceFill([
            'email_verification_token' => $this->hash($token),
            'email_verification_expires_at' => Carbon::now()->addMinutes((int) config('auth.registration.verification_expires_minutes')),
        ])->save();

        return $token;
    }

    public function hasValidSignature(string $token): bool
    {
        return $this->signedTokens->hasValidSignature($token);
    }

    public function hash(string $token): string
    {
        return $this->signedTokens->hash($token);
    }

    public function link(string $token): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/verify-email?token='.urlencode($token);
    }
}
