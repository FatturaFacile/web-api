<?php

declare(strict_types=1);

namespace App\Services\Auth\Sso;

use App\Models\SamlProvider;
use App\Models\SsoProvider;

/**
 * What the account rules of SsoLogin need to know about the identity provider of a tenant, whatever the protocol
 * (OpenID Connect or SAML): the tenant, the provider (to link identities to), the email domains, provisioning and role
 * mapping settings.
 */
final readonly class SsoConnection
{
    public const OIDC = 'oidc';

    public const SAML = 'saml';

    /**
     * @param  list<string>  $allowedDomains
     * @param  array<string, string>|null  $roleMapping
     */
    public function __construct(
        public string $protocol,
        public string $providerId,
        public string $tenantId,
        public array $allowedDomains,
        public bool $jitProvisioning,
        public bool $syncRoleOnLogin,
        public ?string $roleClaim,
        public ?array $roleMapping,
        public ?string $defaultRole,
        public bool $ready,
    ) {}

    /**
     * The column of sso_identities that holds the provider id of this protocol.
     */
    public function identityColumn(): string
    {
        return $this->protocol === self::SAML ? 'saml_provider_id' : 'sso_provider_id';
    }

    public static function fromOidc(SsoProvider $provider): self
    {
        return new self(self::OIDC, (string) $provider->id, (string) $provider->tenant_id, (array) $provider->allowed_email_domains, $provider->jit_provisioning, $provider->sync_role_on_login, $provider->role_claim, $provider->role_mapping, $provider->default_role, $provider->isReady());
    }

    public static function fromSaml(SamlProvider $provider): self
    {
        return new self(self::SAML, (string) $provider->id, (string) $provider->tenant_id, (array) $provider->allowed_email_domains, $provider->jit_provisioning, $provider->sync_role_on_login, $provider->role_attribute, $provider->role_mapping, $provider->default_role, $provider->isReady());
    }

    /**
     * The identity provider settings of a tenant, the ready one first when there are both.
     */
    public static function forTenant(string $tenantId): ?self
    {
        $oidc = SsoProvider::withoutGlobalScopes()->where('tenant_id', $tenantId)->first();
        $saml = SamlProvider::withoutGlobalScopes()->where('tenant_id', $tenantId)->first();
        $connections = array_filter([$oidc === null ? null : self::fromOidc($oidc), $saml === null ? null : self::fromSaml($saml)]);

        foreach ($connections as $connection) {
            if ($connection->ready) {
                return $connection;
            }
        }

        return $connections === [] ? null : array_values($connections)[0];
    }
}
