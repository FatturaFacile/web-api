<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Signed, single-use tokens: only their hash is stored, so a database leak does not expose valid links.
 */
final class EmailVerificationTokens
{
    /**
     * Give the user a new token, invalidating the previous one, and return its raw value for the link.
     */
    public function issue(User $user): string
    {
        $random = Str::random(64);
        $token = $random.'.'.$this->signature($random);

        $user->forceFill([
            'email_verification_token' => $this->hash($token),
            'email_verification_expires_at' => Carbon::now()->addMinutes((int) config('auth.registration.verification_expires_minutes')),
        ])->save();

        return $token;
    }

    public function hasValidSignature(string $token): bool
    {
        $parts = explode('.', $token);

        return count($parts) === 2 && hash_equals($this->signature($parts[0]), $parts[1]);
    }

    public function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function link(string $token): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/verify-email?token='.urlencode($token);
    }

    private function signature(string $random): string
    {
        return hash_hmac('sha256', $random, (string) config('app.key'));
    }
}
