<?php

declare(strict_types=1);

namespace App\Models\Access;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * An email invitation to join the tenant. Only the hash of the token is stored.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $email
 * @property string $role
 * @property string|null $custom_role_id
 * @property array<string, string>|null $modules
 * @property list<string>|null $team_ids
 * @property string $token_hash
 * @property string|null $invited_by
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $revoked_at
 */
class TenantInvitation extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    protected $table = 'tenant_invitations';

    protected $fillable = ['tenant_id', 'email', 'role', 'custom_role_id', 'modules', 'team_ids', 'token_hash', 'invited_by', 'expires_at', 'accepted_at', 'revoked_at', 'last_sent_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'modules' => 'array',
            'team_ids' => 'array',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->revoked_at === null && $this->expires_at->isFuture();
    }

    public function status(): string
    {
        return match (true) {
            $this->accepted_at !== null => 'accepted',
            $this->revoked_at !== null => 'revoked',
            $this->expires_at->isPast() => 'expired',
            default => 'pending',
        };
    }
}
