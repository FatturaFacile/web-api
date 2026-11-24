<?php

declare(strict_types=1);

namespace App\Models\Access;

use App\Models\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * An e-mail invitation to a company. Only the SHA-256 of the token is stored: the token travels in the e-mail and is
 * the credential of the public accept route.
 *
 * @property string $id
 * @property string $email
 * @property string $role
 * @property string|null $custom_role_id
 * @property array<string, string>|null $modules
 * @property list<string>|null $team_ids
 * @property string $status
 * @property string|null $invited_by
 * @property Carbon|null $expires_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $last_sent_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 */
class TenantInvitation extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    public const string Pending = 'pending';

    public const string Accepted = 'accepted';

    public const string Revoked = 'revoked';

    public const string Expired = 'expired';

    protected $table = 'tenant_invitations';

    protected $fillable = ['tenant_id', 'email', 'role', 'custom_role_id', 'modules', 'team_ids', 'token_hash', 'status', 'invited_by', 'expires_at', 'accepted_at', 'last_sent_at', 'revoked_at'];

    protected function casts(): array
    {
        return [
            'modules' => 'array',
            'team_ids' => 'array',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'last_sent_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * The stored status, with a pending invitation past its expiry reported as expired.
     */
    public function effectiveStatus(): string
    {
        if ($this->status === self::Pending && $this->expires_at !== null && $this->expires_at->isPast()) {
            return self::Expired;
        }

        return $this->status;
    }

    public function isOpen(): bool
    {
        return $this->effectiveStatus() === self::Pending;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
