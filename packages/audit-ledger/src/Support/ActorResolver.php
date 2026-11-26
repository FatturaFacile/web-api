<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Support;

use App\Models\User;
use Enterprise\AuditLedger\Domain\Enums\ActorType;
use Enterprise\AuditLedger\Domain\Services\Pseudonymizer;
use Enterprise\AuditLedger\Domain\ValueObjects\ActorRef;
use Illuminate\Http\Request;
use Throwable;

/**
 * Who is acting, as the pseudonymous reference written to the chain: the authenticated user (or the one named by the
 * caller), an API key, the real operator of an impersonation, or the system.
 */
final class ActorResolver
{
    public const string IMPERSONATION_PREFIX = 'impersonation:';

    public function __construct(private readonly Pseudonymizer $pseudonymizer) {}

    public function resolve(string $tenantId, ?string $explicitUserId = null, ?string $explicitType = null): ActorRef
    {
        $request = app()->bound('request') ? app('request') : null;

        if ($explicitUserId === null && $request instanceof Request && is_array($request->attributes->get('api_key_scopes'))) {
            $keyId = (string) ($request->attributes->get('api_key_id') ?? 'api-key');

            return new ActorRef(ActorType::ApiKey, (string) $this->pseudonymizer->subjectToken($tenantId, 'api_key', $keyId, 'API key '.$keyId));
        }

        $user = $explicitUserId !== null ? $this->findUser($explicitUserId) : (auth()->user() instanceof User ? auth()->user() : null);
        $userId = $explicitUserId ?? ($user !== null ? (string) $user->getKey() : null);

        if ($userId === null) {
            return new ActorRef($explicitType !== null ? ActorType::fromStored($explicitType) : ActorType::System);
        }

        $ref = (string) $this->pseudonymizer->subjectToken($tenantId, 'user', $userId, $this->displayName($user, $userId));

        return new ActorRef(ActorType::User, $ref, $this->impersonatorRef($tenantId, $user), $userId);
    }

    private function impersonatorRef(string $tenantId, ?User $user): ?string
    {
        $explicit = $user === null ? null : $this->impersonatorId($user);

        if ($explicit === null) {
            return null;
        }

        $operator = $this->findUser($explicit);

        return (string) $this->pseudonymizer->subjectToken($tenantId, 'user', $explicit, $this->displayName($operator, $explicit));
    }

    private function impersonatorId(User $user): ?string
    {
        try {
            $name = (string) data_get($user->currentAccessToken(), 'name', '');
        } catch (Throwable) {
            return null;
        }

        return str_starts_with($name, self::IMPERSONATION_PREFIX) ? substr($name, strlen(self::IMPERSONATION_PREFIX)) : null;
    }

    private function findUser(string $id): ?User
    {
        try {
            return User::query()->find($id);
        } catch (Throwable) {
            return null;
        }
    }

    private function displayName(?User $user, string $fallbackId): string
    {
        $name = $user !== null ? trim((string) ($user->name ?? '')) : '';

        return $name !== '' ? $name : ($user !== null && (string) $user->email !== '' ? (string) $user->email : 'user '.$fallbackId);
    }
}
