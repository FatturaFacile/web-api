<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\PasswordResetToken;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Single-use tokens that allow choosing a new password.
 */
final class PasswordResetTokens
{
    public function __construct(private readonly SignedTokens $signedTokens) {}

    /**
     * Give the user a new token, invalidating the previous ones. The raw value only exists here, for the link.
     *
     * @return array{token: string, expires_at: Carbon}
     */
    public function issue(User $user): array
    {
        $token = $this->signedTokens->generate();
        $expiresAt = Carbon::now()->addMinutes((int) config('auth.password_reset.expires_minutes'));
        $tenantIds = $user->tenants()->pluck('tenants.id');

        $this->invalidateFor($user);

        PasswordResetToken::create([
            // A user can belong to several tenants, or to none while waiting for approval.
            'tenant_id' => $tenantIds->count() === 1 ? $tenantIds->first() : null,
            'user_id' => $user->id,
            'token_hash' => $this->signedTokens->hash($token),
            'expires_at' => $expiresAt,
            'created_at' => Carbon::now(),
            'created_ip' => request()->ip(),
            'created_user_agent' => request()->userAgent(),
        ]);

        return ['token' => $token, 'expires_at' => $expiresAt];
    }

    public function find(string $token): ?PasswordResetToken
    {
        if (! $this->signedTokens->hasValidSignature($token)) {
            return null;
        }

        return PasswordResetToken::query()->where('token_hash', $this->signedTokens->hash($token))->first();
    }

    /**
     * Used tokens stay until pruned, so that replaying one is told apart from a forged value in the audit trail.
     */
    public function invalidateFor(User $user): void
    {
        PasswordResetToken::query()->where('user_id', $user->id)->whereNull('used_at')->delete();
    }

    public function link(string $token): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/reset-password?token='.urlencode($token);
    }
}
