<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\SsoProviderFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The OpenID Connect identity provider of a tenant (one per tenant). The client secret is stored encrypted and never
 * serialised. SAML is not supported.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $issuer
 * @property string|null $authorization_endpoint
 * @property string|null $token_endpoint
 * @property string|null $jwks_uri
 * @property string $redirect_uri
 * @property string $client_id
 * @property string|null $client_secret
 * @property string $token_endpoint_auth_method
 * @property string|null $end_session_endpoint
 * @property string|null $post_logout_redirect_uri
 * @property string $scopes
 * @property list<string> $allowed_email_domains
 * @property string|null $role_claim
 * @property array<string, string>|null $role_mapping
 * @property string|null $default_role
 * @property bool $sync_role_on_login
 * @property bool $jit_provisioning
 * @property bool $enabled
 * @property Carbon|null $discovered_at
 */
class SsoProvider extends Model
{
    /** @use HasFactory<SsoProviderFactory> */
    use BelongsToTenant, HasFactory, HasUuids;

    protected $guarded = ['id', 'tenant_id', 'created_at', 'updated_at'];

    /**
     * @var list<string>
     */
    protected $hidden = ['client_secret'];

    protected function casts(): array
    {
        return [
            'client_secret' => 'encrypted',
            'allowed_email_domains' => 'array',
            'role_mapping' => 'array',
            'sync_role_on_login' => 'boolean',
            'jit_provisioning' => 'boolean',
            'enabled' => 'boolean',
            'discovered_at' => 'datetime',
        ];
    }

    public function isReady(): bool
    {
        return $this->enabled
            && $this->authorization_endpoint !== null
            && $this->token_endpoint !== null
            && $this->jwks_uri !== null;
    }
}
