<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A sealed WORM archive batch of the audit trail (unscoped like the trail itself: the readers filter the tenant).
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property int $from_sequence
 * @property int $to_sequence
 * @property int $record_count
 * @property string $merkle_root_hash
 * @property string $storage_disk
 * @property string $storage_path
 * @property string $manifest_path
 * @property Carbon $retention_until
 * @property Carbon $sealed_at
 */
class AuditWormBatch extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'from_sequence' => 'integer',
            'to_sequence' => 'integer',
            'record_count' => 'integer',
            'retention_until' => 'datetime',
            'sealed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<AuditWormBatchEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(AuditWormBatchEntry::class, 'batch_id');
    }
}
