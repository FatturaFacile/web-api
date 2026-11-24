<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One started SSO login: only the hashes of the state and of the nonce are kept, the PKCE verifier is encrypted. A state
 * is consumed at most once, so a replayed callback finds nothing to use.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $sso_provider_id
 * @property string $state_hash
 * @property string $nonce_hash
 * @property string $code_verifier
 * @property string|null $browser_binding_hash
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 */
class SsoLoginState extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = ['id', 'tenant_id', 'created_at', 'updated_at'];

    /**
     * @var list<string>
     */
    protected $hidden = ['code_verifier', 'state_hash', 'nonce_hash', 'browser_binding_hash'];

    protected function casts(): array
    {
        return [
            'code_verifier' => 'encrypted',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }
}
