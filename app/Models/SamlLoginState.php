<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One started SAML login: the RelayState is kept as a hash, the AuthnRequest id (the InResponseTo the response must
 * carry) is kept to be checked. Consumed at most once.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $saml_provider_id
 * @property string $relay_state_hash
 * @property string $request_id
 * @property string|null $browser_binding_hash
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 */
class SamlLoginState extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = ['id', 'tenant_id', 'created_at', 'updated_at'];

    /**
     * @var list<string>
     */
    protected $hidden = ['relay_state_hash', 'request_id', 'browser_binding_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }
}
