<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The open accesses of a user: the Bearer tokens (mobile apps, integrations) and the cookie sessions of the browser
 * (the SPA). Both can be listed and revoked one by one or all together.
 */
final class UserSessions
{
    public const KIND_TOKEN = 'token';

    public const KIND_SESSION = 'session';

    /**
     * @return list<array{kind: string, id: string, name: string|null, ip_address: string|null, user_agent: string|null, last_used_at: string|null, expires_at: string|null, current: bool}>
     */
    public function list(User $user, ?string $currentTokenId, ?string $currentSessionId): array
    {
        $tokens = $user->tokens()->orderByDesc('last_used_at')->orderByDesc('created_at')->get()
            ->map(fn (PersonalAccessToken $token): array => [
                'kind' => self::KIND_TOKEN,
                'id' => (string) $token->getKey(),
                'name' => $token->name,
                'ip_address' => null,
                'user_agent' => null,
                'last_used_at' => $token->last_used_at?->toIso8601String(),
                'expires_at' => $this->tokenExpiry($token)?->toIso8601String(),
                'current' => $currentTokenId !== null && (string) $token->getKey() === $currentTokenId,
            ]);

        $sessions = DB::table('sessions')->where('user_id', $user->getKey())->orderByDesc('last_activity')->get()
            ->map(fn (object $session): array => [
                'kind' => self::KIND_SESSION,
                'id' => hash('sha256', (string) $session->id),
                'name' => null,
                'ip_address' => $session->ip_address,
                'user_agent' => $session->user_agent,
                'last_used_at' => Carbon::createFromTimestamp((int) $session->last_activity)->toIso8601String(),
                'expires_at' => Carbon::createFromTimestamp((int) $session->last_activity)->addMinutes((int) config('session.lifetime'))->toIso8601String(),
                'current' => $currentSessionId !== null && hash_equals((string) $session->id, $currentSessionId),
            ]);

        return [...$tokens->all(), ...$sessions->all()];
    }

    /**
     * Revokes one access of the user. Sessions are addressed by the hash of their id: the id itself is the cookie value.
     */
    public function revoke(User $user, string $kind, string $id): bool
    {
        if ($kind === self::KIND_TOKEN) {
            return $user->tokens()->whereKey($id)->delete() > 0;
        }

        $session = DB::table('sessions')->where('user_id', $user->getKey())->get(['id'])
            ->first(fn (object $row): bool => hash_equals(hash('sha256', (string) $row->id), $id));

        return $session !== null && DB::table('sessions')->where('id', $session->id)->delete() > 0;
    }

    /**
     * Revokes every access of the user but the current one.
     *
     * @return array{tokens: int, sessions: int}
     */
    public function revokeOthers(User $user, ?string $currentTokenId, ?string $currentSessionId): array
    {
        return [
            'tokens' => $user->tokens()->when($currentTokenId !== null, fn ($query) => $query->whereKeyNot($currentTokenId))->delete(),
            'sessions' => DB::table('sessions')->where('user_id', $user->getKey())
                ->when($currentSessionId !== null, fn ($query) => $query->where('id', '!=', $currentSessionId))
                ->delete(),
        ];
    }

    /**
     * Revokes every token and every session of the user: deactivation, password reset or change, lost second factor.
     *
     * @return array{tokens: int, sessions: int}
     */
    public function revokeAll(User $user): array
    {
        return $this->revokeOthers($user, null, null);
    }

    /**
     * The id of the Bearer token of the request, or null for a cookie session (or a token without a stored row).
     */
    public static function currentTokenId(User $user): ?string
    {
        $token = $user->currentAccessToken();
        $id = $token instanceof PersonalAccessToken ? (string) $token->getKey() : '';

        return $id === '' ? null : $id;
    }

    private function tokenExpiry(PersonalAccessToken $token): ?Carbon
    {
        if ($token->expires_at !== null) {
            return Carbon::parse($token->expires_at);
        }

        $minutes = config('sanctum.expiration');

        return $minutes === null ? null : Carbon::parse($token->created_at)->addMinutes((int) $minutes);
    }
}
