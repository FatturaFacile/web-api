<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\SsoProvider;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The OpenID Connect provider of a tenant. The client secret is never part of it, only whether one is set.
 *
 * @mixin SsoProvider
 */
final class SsoProviderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'issuer' => $this->issuer,
            'authorization_endpoint' => $this->authorization_endpoint,
            'token_endpoint' => $this->token_endpoint,
            'jwks_uri' => $this->jwks_uri,
            'redirect_uri' => $this->redirect_uri,
            'client_id' => $this->client_id,
            'has_client_secret' => $this->client_secret !== null && $this->client_secret !== '',
            'scopes' => $this->scopes,
            'allowed_email_domains' => $this->allowed_email_domains,
            'role_claim' => $this->role_claim,
            'role_mapping' => $this->role_mapping,
            'default_role' => $this->default_role,
            'sync_role_on_login' => $this->sync_role_on_login,
            'jit_provisioning' => $this->jit_provisioning,
            'enabled' => $this->enabled,
            'ready' => $this->isReady(),
            'discovered_at' => $this->discovered_at?->toIso8601String(),
        ];
    }
}
