<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MobileDeviceSession extends Model
{
    use BelongsToTenant, HasUuids;

    public const PLATFORM_IOS = 'ios';

    public const PLATFORM_ANDROID = 'android';

    public const PLATFORM_ANDROID_RUGGED_ZEBRA = 'android_rugged_zebra';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'device_id',
        'device_name',
        'platform',
        'push_provider',
        'push_token',
        'app_version',
        'biometric_enrolled',
        'access_token_id',
        'last_active_at',
        'is_revoked',
        'revoked_at',
        'revoke_reason',
        'wipe_requested_at',
        'wipe_acknowledged_at',
    ];

    protected $hidden = ['push_token'];

    protected function casts(): array
    {
        return [
            'biometric_enrolled' => 'boolean',
            'is_revoked' => 'boolean',
            'last_active_at' => 'datetime',
            'revoked_at' => 'datetime',
            'wipe_requested_at' => 'datetime',
            'wipe_acknowledged_at' => 'datetime',
        ];
    }

    public function needsWipe(): bool
    {
        return $this->is_revoked && $this->wipe_requested_at !== null && $this->wipe_acknowledged_at === null;
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<MobileOfflineCacheManifest, $this> */
    public function manifests(): HasMany
    {
        return $this->hasMany(MobileOfflineCacheManifest::class);
    }
}
