<?php

declare(strict_types=1);

namespace App\Services\Auth\Sso;

use App\Models\SsoIdentity;
use App\Models\SsoProvider;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\RegistrationAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns a validated id_token into a local user of the tenant, or refuses. The rules, in order:
 *
 * 1. the email must be present, verified by the provider (`email_verified`) and in one of the allowed domains of the tenant;
 * 2. a known (provider, `sub`) pair resolves to its user;
 * 3. otherwise the email is matched, case-insensitively, to an existing member of the tenant and the identity is linked;
 * 4. an email that belongs to a user who is not a member of this tenant is never linked: the provider of one company must
 *    not take over an account that exists elsewhere (account_conflict);
 * 5. an unknown email creates a user only when just-in-time provisioning is on and a role can be resolved from the claims.
 *
 * Platform administrators cannot sign in through a tenant provider. Every outcome is written to the audit ledger.
 */
final class SsoLogin
{
    public function __construct(
        private readonly OidcClient $client,
        private readonly SsoRoleMapper $roles,
        private readonly RegistrationAudit $audit,
    ) {}

    /**
     * Starts a login: the audit entry and the URL to send the browser to.
     *
     * @throws SsoException when the tenant has no ready provider
     */
    public function start(string $tenantId): string
    {
        /** @var SsoProvider|null $provider */
        $provider = SsoProvider::withoutGlobalScopes()->where('tenant_id', $tenantId)->first();

        if ($provider === null || ! $provider->isReady()) {
            throw (new SsoException('provider_unavailable'))->forTenant($tenantId);
        }

        $url = $this->client->begin($provider);
        $this->audit->record(RegistrationAudit::SSO_LOGIN_STARTED, null, ['provider' => $provider->id], $tenantId);

        return $url;
    }

    /**
     * @return array{0: User, 1: Tenant}
     *
     * @throws SsoException
     */
    public function complete(string $code, string $state): array
    {
        $tenantId = null;

        try {
            [$provider, $claims] = $this->client->complete($code, $state);
            $tenantId = (string) $provider->tenant_id;

            return $this->signIn($provider, $claims);
        } catch (SsoException $exception) {
            $this->audit->record(RegistrationAudit::SSO_LOGIN_FAILED, null, ['reason' => $exception->reason], $exception->tenantId ?? $tenantId);

            throw $exception->forTenant($exception->tenantId ?? $tenantId);
        }
    }

    /**
     * @param  array<string, mixed>  $claims
     * @return array{0: User, 1: Tenant}
     *
     * @throws SsoException
     */
    private function signIn(SsoProvider $provider, array $claims): array
    {
        $tenantId = (string) $provider->tenant_id;
        $subject = (string) $claims['sub'];
        $email = $this->verifiedEmail($provider, $claims);

        /** @var Tenant|null $tenant */
        $tenant = Tenant::query()->find($tenantId);

        if ($tenant === null || ! in_array($tenant->status, [null, 'active'], true)) {
            throw new SsoException('tenant_unavailable');
        }

        $identity = SsoIdentity::withoutGlobalScopes()->where('sso_provider_id', $provider->id)->where('subject', $subject)->first();
        $user = $identity === null ? null : User::query()->find($identity->user_id);
        $provisioned = false;

        if ($user === null) {
            [$user, $provisioned] = $this->userForEmail($provider, $tenant, $email, $claims);
        }

        if (! $user->is_active) {
            throw new SsoException('account_disabled');
        }

        if ($user->is_platform_admin) {
            throw new SsoException('platform_admin_not_allowed');
        }

        $this->assertActiveMember($user, $tenantId);

        if ($identity === null) {
            // A user is linked to one subject of a provider: another subject for the same email is not the same person.
            if (SsoIdentity::withoutGlobalScopes()->where('sso_provider_id', $provider->id)->where('user_id', $user->id)->exists()) {
                throw new SsoException('identity_conflict');
            }

            $this->link($provider, $user, $subject, $provisioned);
        } else {
            $identity->forceFill(['last_login_at' => now()])->save();
        }

        $this->completeAccount($provider, $user, $tenantId, $claims, $provisioned);

        $this->audit->record(RegistrationAudit::SSO_LOGIN_SUCCEEDED, (string) $user->id, ['provider' => $provider->id, 'provisioned' => $provisioned], $tenantId);

        return [$user, $tenant];
    }

    /**
     * @param  array<string, mixed>  $claims
     *
     * @throws SsoException
     */
    private function verifiedEmail(SsoProvider $provider, array $claims): string
    {
        $email = $claims['email'] ?? null;

        if (! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new SsoException('email_missing');
        }

        $verified = $claims['email_verified'] ?? false;

        if ($verified !== true && $verified !== 'true') {
            throw new SsoException('email_not_verified');
        }

        $email = Str::lower(trim($email));
        $domain = Str::after($email, '@');
        $allowed = array_map(fn (mixed $item): string => Str::lower(trim((string) $item)), (array) $provider->allowed_email_domains);

        if (! in_array($domain, $allowed, true)) {
            throw new SsoException('email_domain_not_allowed', $domain);
        }

        return $email;
    }

    /**
     * @param  array<string, mixed>  $claims
     * @return array{0: User, 1: bool} the user and whether it was just created
     *
     * @throws SsoException
     */
    private function userForEmail(SsoProvider $provider, Tenant $tenant, string $email, array $claims): array
    {
        $existing = User::withTrashed()->whereRaw('lower(email) = ?', [$email])->first();

        if ($existing !== null) {
            if ($existing->trashed()) {
                throw new SsoException('account_unavailable');
            }

            if (! DB::table('tenant_user')->where('tenant_id', $tenant->id)->where('user_id', $existing->id)->exists()) {
                throw new SsoException('account_conflict');
            }

            return [$existing, false];
        }

        if (! $provider->jit_provisioning) {
            throw new SsoException('user_not_provisioned');
        }

        $role = $this->roles->resolve($provider, $claims);

        if ($role === null) {
            throw new SsoException('role_not_resolved');
        }

        return [$this->provision($tenant, $email, $role, $claims), true];
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function provision(Tenant $tenant, string $email, string $role, array $claims): User
    {
        return DB::transaction(function () use ($tenant, $email, $role, $claims): User {
            $name = is_string($claims['name'] ?? null) && trim($claims['name']) !== ''
                ? trim($claims['name'])
                : Str::before($email, '@');

            $user = User::query()->create([
                'name' => Str::limit($name, 250, ''),
                'email' => $email,
                'password' => Str::random(64),
                'locale' => $this->locale($claims),
                'is_active' => true,
                'email_verified_at' => now(),
            ]);

            $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role, 'provisioned_via' => 'sso']);

            return $user;
        });
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function locale(array $claims): string
    {
        $locale = is_string($claims['locale'] ?? null) ? Str::lower(substr($claims['locale'], 0, 2)) : '';

        return in_array($locale, ['it', 'en', 'de', 'fr'], true) ? $locale : 'it';
    }

    /**
     * @throws SsoException when the membership is missing or suspended
     */
    private function assertActiveMember(User $user, string $tenantId): void
    {
        if (! $user->belongsToTenant($tenantId)) {
            throw new SsoException('membership_unavailable');
        }
    }

    private function link(SsoProvider $provider, User $user, string $subject, bool $provisioned): void
    {
        $identity = new SsoIdentity(['sso_provider_id' => $provider->id, 'user_id' => $user->id, 'subject' => $subject, 'last_login_at' => now()]);
        $identity->forceFill(['tenant_id' => $provider->tenant_id])->save();

        $this->audit->record($provisioned ? RegistrationAudit::SSO_USER_PROVISIONED : RegistrationAudit::SSO_IDENTITY_LINKED, (string) $user->id, ['provider' => $provider->id], (string) $provider->tenant_id);
    }

    /**
     * The provider has verified the email, so a local account that was waiting for the verification is verified now; the
     * role follows the claims only when the tenant asked for it.
     *
     * @param  array<string, mixed>  $claims
     */
    private function completeAccount(SsoProvider $provider, User $user, string $tenantId, array $claims, bool $provisioned): void
    {
        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        if ($provider->sync_role_on_login && ! $provisioned) {
            $role = $this->roles->resolve($provider, $claims);
            $current = $user->getRoleInTenant($tenantId);

            // An owner is never changed from outside, and a role is never removed for lack of a match.
            if ($role !== null && $current !== 'owner' && $current !== $role) {
                $user->tenants()->updateExistingPivot($tenantId, ['role' => $role]);
            }
        }
    }
}
