<?php

declare(strict_types=1);

namespace Modules\Platform\Identity\Application;

use App\Models\User;
use DomainException;
use Modules\Platform\Identity\Domain\Events\ImpersonationEnded;
use Modules\Platform\Identity\Domain\Events\ImpersonationStarted;

/**
 * Support impersonation: only an owner of the tenant, only with a recorded consent reference, never of another owner
 * or of oneself, and the access lasts at most 30 minutes. It is a Sanctum token of the target named
 * `impersonation:<actor id>`; both ends are written to the immutable audit ledger by RecordSecurityEvent.
 */
final class ImpersonationService
{
    public const int MAX_TTL_MINUTES = 30;

    public const string TOKEN_PREFIX = 'impersonation:';

    /**
     * @return array{token: string, expires_at: string}
     */
    public function start(User $actor, User $target, string $tenantId, string $consentReference, int $ttlMinutes = self::MAX_TTL_MINUTES): array
    {
        if ($actor->is($target)) {
            throw new DomainException('You cannot impersonate yourself.');
        }
        if ($actor->getRoleInTenant($tenantId) !== 'owner') {
            throw new DomainException('Only an owner can start an impersonation.');
        }
        if ($target->getRoleInTenant($tenantId) === 'owner') {
            throw new DomainException('An owner cannot be impersonated.');
        }
        if (trim($consentReference) === '') {
            throw new DomainException('A recorded consent is required.');
        }

        $expiresAt = now()->addMinutes(max(1, min($ttlMinutes, self::MAX_TTL_MINUTES)));
        $token = $target->createToken(self::TOKEN_PREFIX.$actor->id, ['*'], $expiresAt);

        event(new ImpersonationStarted($tenantId, (string) $actor->id, (string) $target->id, (string) $actor->id));

        return ['token' => $token->plainTextToken, 'expires_at' => $expiresAt->toIso8601String()];
    }

    /** Ends the impersonation tokens the actor opened on a target; returns how many were closed. */
    public function end(User $actor, User $target, string $tenantId): int
    {
        $closed = $target->tokens()->where('name', self::TOKEN_PREFIX.$actor->id)->delete();

        if ($closed > 0) {
            event(new ImpersonationEnded($tenantId, (string) $actor->id, (string) $target->id, (string) $actor->id));
        }

        return $closed;
    }
}
