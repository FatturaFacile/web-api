<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A user linked to the subject (`sub`) of the identity provider of a tenant.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $sso_provider_id
 * @property string $user_id
 * @property string $subject
 * @property Carbon|null $last_login_at
 * @property string|null $last_id_token
 * @property string|null $last_session_index
 */
class SsoIdentity extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = ['id', 'tenant_id', 'created_at', 'updated_at'];

    /**
     * @var list<string>
     */
    protected $hidden = ['last_id_token'];

    protected function casts(): array
    {
        return ['last_login_at' => 'datetime', 'last_id_token' => 'encrypted'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
