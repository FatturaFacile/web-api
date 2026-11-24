<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\SamlProvider;
use App\Services\Auth\Saml\SamlSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The SAML provider of a tenant with the service provider URLs to give to the identity provider. The private key of the
 * service provider is never part of it.
 *
 * @mixin SamlProvider
 */
final class SamlProviderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $tenantId = (string) $this->tenant_id;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'idp_entity_id' => $this->idp_entity_id,
            'idp_sso_url' => $this->idp_sso_url,
            'idp_slo_url' => $this->idp_slo_url,
            'idp_certificates_count' => count($this->idp_certificates),
            'idp_metadata_url' => $this->idp_metadata_url,
            'metadata_refreshed_at' => $this->metadata_refreshed_at?->toIso8601String(),
            'email_attribute' => $this->email_attribute,
            'name_attribute' => $this->name_attribute,
            'role_attribute' => $this->role_attribute,
            'role_mapping' => $this->role_mapping,
            'default_role' => $this->default_role,
            'sync_role_on_login' => $this->sync_role_on_login,
            'jit_provisioning' => $this->jit_provisioning,
            'allowed_email_domains' => $this->allowed_email_domains,
            'has_sp_signing_key' => $this->sp_private_key !== null && $this->sp_private_key !== '',
            'enabled' => $this->enabled,
            'ready' => $this->isReady(),
            'sp' => [
                'entity_id' => SamlSettings::entityId($tenantId),
                'acs_url' => SamlSettings::acsUrl($tenantId),
                'slo_url' => SamlSettings::sloUrl($tenantId),
                'metadata_url' => SamlSettings::entityId($tenantId),
            ],
        ];
    }
}
