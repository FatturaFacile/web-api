<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A SCIM 2.0 bearer token of a tenant. Only the SHA-256 of the token is stored (the token is a 256-bit random value,
 * so a fast hash is enough); the plain value is shown once, at rotation.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $token_hash
 * @property string $token_hint
 * @property string|null $created_by
 * @property Carbon|null $last_used_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $revoked_at
 */
class ScimToken extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = ['id', 'tenant_id', 'created_at', 'updated_at'];

    /**
     * @var list<string>
     */
    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
