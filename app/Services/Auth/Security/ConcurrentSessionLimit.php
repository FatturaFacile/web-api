<?php

declare(strict_types=1);

namespace App\Services\Auth\Security;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\RegistrationAudit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Keeps the open accesses of a user (Bearer tokens and browser sessions) within the maximum of the tenant policy: when a
 * new one is opened, the least recently used others are revoked until the new one fits. The new access is never revoked.
 */
final class ConcurrentSessionLimit
{
    public function __construct(
        private readonly TenantSecurityPolicies $policies,
        private readonly RegistrationAudit $audit,
    ) {}

    /**
     * @return int how many accesses were revoked
     */
    public function enforce(User $user, ?Tenant $tenant, ?string $currentTokenId, ?string $currentSessionId): int
    {
        $max = $this->policies->forTenant($tenant)->maxConcurrentSessions;

        if ($max === null || $max < 1) {
            return 0;
        }

        $others = [];

        foreach ($user->tokens()->when($currentTokenId !== null, fn ($query) => $query->whereKeyNot($currentTokenId))->get() as $token) {
            /** @var PersonalAccessToken $token */
            $others[] = ['kind' => 'token', 'id' => (string) $token->getKey(), 'at' => $this->lastActivity($token)];
        }

        $sessions = DB::table('sessions')->where('user_id', $user->getKey())
            ->when($currentSessionId !== null, fn ($query) => $query->where('id', '!=', $currentSessionId))
            ->get(['id', 'last_activity']);

        foreach ($sessions as $session) {
            $others[] = ['kind' => 'session', 'id' => (string) $session->id, 'at' => Carbon::createFromTimestamp((int) $session->last_activity)];
        }

        // The new access takes one place; the most recently used others keep the rest.
        usort($others, fn (array $a, array $b): int => $b['at']->getTimestamp() <=> $a['at']->getTimestamp());
        $surplus = array_slice($others, $max - 1);

        foreach ($surplus as $access) {
            if ($access['kind'] === 'token') {
                $user->tokens()->whereKey($access['id'])->delete();
            } else {
                DB::table('sessions')->where('id', $access['id'])->delete();
            }
        }

        if ($surplus !== []) {
            $this->audit->record(RegistrationAudit::SESSION_LIMIT_REVOKED, (string) $user->id, ['revoked' => count($surplus), 'maximum' => $max], $tenant?->id);
        }

        return count($surplus);
    }

    private function lastActivity(PersonalAccessToken $token): Carbon
    {
        $at = $token->getAttribute('last_activity_at') ?? $token->last_used_at ?? $token->created_at;

        return Carbon::parse($at);
    }
}
