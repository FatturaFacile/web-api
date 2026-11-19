<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Link between a sealed batch and an audit record.
 *
 * @property int $id
 * @property string|null $tenant_id
 * @property string $batch_id
 * @property string $audit_log_id
 */
class AuditWormBatchEntry extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return BelongsTo<AuditWormBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(AuditWormBatch::class, 'batch_id');
    }
}
