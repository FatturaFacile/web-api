<?php

declare(strict_types=1);

namespace Modules\Platform\Identity\Application\Passkeys;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Modules\Platform\Identity\Domain\Events\PasskeyRegistered;
use Modules\Platform\Identity\Domain\Events\PasskeyRenamed;
use Modules\Platform\Identity\Domain\Events\PasskeyRevoked;
use Modules\Platform\Identity\Infrastructure\Models\WebAuthnCredential;

/**
 * Passkey ceremonies of the application: challenges live in the cache and are single use, credentials belong to the
 * user (not to one tenant), a revoked credential never signs in, and a signature counter that does not grow revokes the
 * credential as a probable clone.
 */
final class PasskeyService
{
    public function __construct(private readonly PasskeyAuthenticator $authenticator) {}

    /**
     * @return array{ceremony_id: string, options: array<string, mixed>}
     */
    public function beginRegistration(User $user): array
    {
        $existing = WebAuthnCredential::query()->where('user_id', $user->id)->whereNull('revoked_at')->pluck('credential_id')->all();
        $begun = $this->authenticator->registrationOptions((string) $user->id, (string) $user->email, (string) $user->name, array_values($existing));

        return ['ceremony_id' => $this->remember(['kind' => 'register', 'user_id' => (string) $user->id, 'state' => $begun['state']]), 'options' => $begun['options']];
    }

    /**
     * @param  array<string, mixed>  $credential
     *
     * @throws PasskeyException
     */
    public function finishRegistration(User $user, ?string $tenantId, string $ceremonyId, string $name, array $credential): WebAuthnCredential
    {
        $ceremony = $this->take($ceremonyId, 'register');
        if ($ceremony['user_id'] !== (string) $user->id) {
            throw new PasskeyException(PasskeyException::INVALID, 'The ceremony belongs to another user.');
        }

        $verified = $this->authenticator->verifyRegistration($ceremony['state'], $credential);

        if (WebAuthnCredential::query()->where('credential_id', $verified['credential_id'])->exists()) {
            throw new PasskeyException(PasskeyException::INVALID, 'The credential is already registered.');
        }

        $stored = WebAuthnCredential::create([
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'name' => $name,
            'credential_id' => $verified['credential_id'],
            'public_key' => $verified['record'],
            'sign_count' => $verified['sign_count'],
            'aaguid' => $verified['aaguid'],
        ]);

        if ($tenantId !== null) {
            event(new PasskeyRegistered($tenantId, (string) $user->id, (string) $stored->id, (string) $user->id));
        }

        return $stored;
    }

    /**
     * @return array{ceremony_id: string, options: array<string, mixed>}
     */
    public function beginLogin(): array
    {
        $begun = $this->authenticator->loginOptions();

        return ['ceremony_id' => $this->remember(['kind' => 'login', 'state' => $begun['state']]), 'options' => $begun['options']];
    }

    /**
     * Verifies an assertion and returns the user who owns the credential.
     *
     * @param  array<string, mixed>  $credential
     *
     * @throws PasskeyException
     */
    public function finishLogin(string $ceremonyId, array $credential): User
    {
        $ceremony = $this->take($ceremonyId, 'login');
        $stored = $this->verifyAssertion($ceremony, $credential, null);

        /** @var User */
        return User::query()->findOrFail($stored->user_id);
    }

    /**
     * Whether the user has at least one active credential, so a security key or passkey can answer a second-factor
     * challenge.
     */
    public function hasCredentials(User $user): bool
    {
        return WebAuthnCredential::query()->where('user_id', $user->id)->whereNull('revoked_at')->exists();
    }

    /**
     * Assertion options of a second-factor challenge: restricted to the active credentials of the challenged user, so a
     * credential of another user never answers it.
     *
     * @return array{ceremony_id: string, options: array<string, mixed>}
     *
     * @throws PasskeyException when the user has no credential
     */
    public function beginSecondFactor(User $user): array
    {
        $ids = WebAuthnCredential::query()->where('user_id', $user->id)->whereNull('revoked_at')->pluck('credential_id')->all();

        if ($ids === []) {
            throw new PasskeyException(PasskeyException::UNKNOWN);
        }

        $begun = $this->authenticator->loginOptions(array_values($ids));

        return [
            'ceremony_id' => $this->remember(['kind' => 'second_factor', 'user_id' => (string) $user->id, 'state' => $begun['state']]),
            'options' => $begun['options'],
        ];
    }

    /**
     * Verifies the assertion that answers a second-factor challenge. The credential must belong to the challenged user;
     * the signature counter check (clone detection) and the single-use ceremony are the ones of the passkey login.
     *
     * @param  array<string, mixed>  $credential
     *
     * @throws PasskeyException
     */
    public function finishSecondFactor(User $user, string $ceremonyId, array $credential): WebAuthnCredential
    {
        $ceremony = $this->take($ceremonyId, 'second_factor');

        if (($ceremony['user_id'] ?? null) !== (string) $user->id) {
            throw new PasskeyException(PasskeyException::INVALID, 'The ceremony belongs to another user.');
        }

        return $this->verifyAssertion($ceremony, $credential, (string) $user->id);
    }

    /**
     * @param  array<string, string>  $ceremony
     * @param  array<string, mixed>  $credential
     *
     * @throws PasskeyException
     */
    private function verifyAssertion(array $ceremony, array $credential, ?string $expectedUserId): WebAuthnCredential
    {
        $peek = $this->authenticator->peekAssertion($credential);

        $stored = WebAuthnCredential::query()->where('credential_id', $peek['credential_id'])->first();
        if ($stored === null) {
            throw new PasskeyException(PasskeyException::UNKNOWN);
        }
        if ($expectedUserId !== null && (string) $stored->user_id !== $expectedUserId) {
            throw new PasskeyException(PasskeyException::UNKNOWN);
        }
        if ($stored->isRevoked()) {
            throw new PasskeyException(PasskeyException::REVOKED);
        }
        if ($peek['user_handle'] !== null && $peek['user_handle'] !== (string) $stored->user_id) {
            throw new PasskeyException(PasskeyException::INVALID, 'The user handle does not match the credential.');
        }

        try {
            $result = $this->authenticator->verifyAssertion($ceremony['state'], $credential, $stored->public_key);
        } catch (PasskeyException $e) {
            if ($e->reason === PasskeyException::CLONED) {
                $stored->forceFill(['revoked_at' => now()])->save();
                $this->announceRevoked($stored, 'cloned');
            }

            throw $e;
        }

        $stored->forceFill(['public_key' => $result['record'], 'sign_count' => $result['sign_count'], 'last_used_at' => now()])->save();

        return $stored;
    }

    public function revoke(User $user, string $credentialId): bool
    {
        $stored = WebAuthnCredential::query()->where('user_id', $user->id)->whereNull('revoked_at')->whereKey($credentialId)->first();
        if ($stored === null) {
            return false;
        }

        $stored->forceFill(['revoked_at' => now()])->save();
        $this->announceRevoked($stored, 'user');

        return true;
    }

    /**
     * Renames one active passkey of the user; null when it is not theirs (or revoked).
     */
    public function rename(User $user, string $credentialId, string $name, ?string $tenantId): ?WebAuthnCredential
    {
        $stored = WebAuthnCredential::query()->where('user_id', $user->id)->whereNull('revoked_at')->whereKey($credentialId)->first();

        if ($stored === null) {
            return null;
        }

        $stored->forceFill(['name' => $name])->save();

        $tenantId ??= $stored->tenant_id ?? $user->tenants()->value('tenants.id');

        if (is_string($tenantId)) {
            event(new PasskeyRenamed($tenantId, (string) $user->id, (string) $stored->id, (string) $user->id));
        }

        return $stored;
    }

    /**
     * Number of active (not revoked) credentials of the user.
     */
    public function activeCount(User $user): int
    {
        return WebAuthnCredential::query()->where('user_id', $user->id)->whereNull('revoked_at')->count();
    }

    /**
     * @return Collection<int, WebAuthnCredential>
     */
    public function list(User $user): Collection
    {
        return WebAuthnCredential::query()->where('user_id', $user->id)->whereNull('revoked_at')->orderBy('created_at')->get();
    }

    private function announceRevoked(WebAuthnCredential $credential, string $by): void
    {
        $tenantId = $credential->tenant_id ?? User::query()->find($credential->user_id)?->tenants()->value('tenants.id');

        if (is_string($tenantId)) {
            event(new PasskeyRevoked($tenantId, (string) $credential->user_id, $by, (string) $credential->id));
        }
    }

    /**
     * @param  array<string, string>  $payload
     */
    private function remember(array $payload): string
    {
        $id = (string) Str::uuid();
        Cache::put("passkey:ceremony:{$id}", $payload, (int) config('passkeys.ceremony_ttl'));

        return $id;
    }

    /**
     * @return array<string, string>
     *
     * @throws PasskeyException
     */
    private function take(string $ceremonyId, string $kind): array
    {
        $ceremony = Cache::pull("passkey:ceremony:{$ceremonyId}");

        if (! is_array($ceremony) || ($ceremony['kind'] ?? null) !== $kind) {
            throw new PasskeyException(PasskeyException::EXPIRED);
        }

        return $ceremony;
    }
}
