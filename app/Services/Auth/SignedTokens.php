<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Illuminate\Support\Str;

/**
 * Signed random tokens for email links. Forged values are rejected without touching the database, and only the hash is
 * stored, so a database leak does not expose valid links.
 */
final class SignedTokens
{
    public function generate(): string
    {
        $random = Str::random(64);

        return $random.'.'.$this->signature($random);
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

    private function signature(string $random): string
    {
        return hash_hmac('sha256', $random, (string) config('app.key'));
    }
}
