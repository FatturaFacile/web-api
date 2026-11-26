<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A browser or app the user chose to remember at the second-factor challenge. It belongs to the user (who can be a
 * member of several tenants), so it is not tenant-scoped; only the SHA-256 hash of its token is stored.
 *
 * @property string $id
 * @property string $user_id
 * @property string $token_hash
 * @property string|null $label
 * @property string|null $ip_address
 * @property Carbon|null $last_used_at
 * @property Carbon $expires_at
 */
final class TrustedDevice extends Model
{
    use HasUuids, MassPrunable;

    protected $table = 'trusted_devices';

    protected $fillable = ['user_id', 'token_hash', 'label', 'ip_address', 'last_used_at', 'expires_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Expired devices are deleted by the daily model:prune.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return self::query()->where('expires_at', '<=', now());
    }
}
