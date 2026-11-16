<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MobileOfflineCacheManifest extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id',
        'mobile_device_session_id',
        'entity_name',
        'last_synced_at',
        'record_count',
        'storage_bytes',
    ];

    protected function casts(): array
    {
        return [
            'last_synced_at' => 'datetime',
            'record_count' => 'integer',
            'storage_bytes' => 'integer',
        ];
    }

    /** @return BelongsTo<MobileDeviceSession, $this> */
    public function deviceSession(): BelongsTo
    {
        return $this->belongsTo(MobileDeviceSession::class, 'mobile_device_session_id');
    }
}
