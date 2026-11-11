<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\SamlProviderFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The SAML 2.0 identity provider of a tenant (one per tenant), SP-initiated with the HTTP-Redirect binding for requests
 * and the HTTP-POST binding for the response. The certificates of the identity provider are public; the optional private
 * key the service provider signs its requests with is stored encrypted and never serialised.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $idp_entity_id
 * @property string $idp_sso_url
 * @property string|null $idp_slo_url
 * @property list<string> $idp_certificates
 * @property string|null $idp_metadata_url
 * @property Carbon|null $metadata_refreshed_at
 * @property string|null $email_attribute
 * @property string|null $name_attribute
 * @property string|null $role_attribute
 * @property array<string, string>|null $role_mapping
 * @property string|null $default_role
 * @property bool $sync_role_on_login
 * @property bool $jit_provisioning
 * @property list<string> $allowed_email_domains
 * @property string|null $sp_private_key
 * @property string|null $sp_certificate
 * @property bool $enabled
 */
class SamlProvider extends Model
{
    /** @use HasFactory<SamlProviderFactory> */
    use BelongsToTenant, HasFactory, HasUuids;

    protected $guarded = ['id', 'tenant_id', 'created_at', 'updated_at'];

    /**
     * @var list<string>
     */
    protected $hidden = ['sp_private_key'];

    protected function casts(): array
    {
        return [
            'idp_certificates' => 'array',
            'role_mapping' => 'array',
            'allowed_email_domains' => 'array',
            'sync_role_on_login' => 'boolean',
            'jit_provisioning' => 'boolean',
            'enabled' => 'boolean',
            'sp_private_key' => 'encrypted',
            'metadata_refreshed_at' => 'datetime',
        ];
    }

    public function isReady(): bool
    {
        return $this->enabled && $this->idp_sso_url !== '' && $this->idp_entity_id !== '' && $this->idp_certificates !== [];
    }
}
