<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A bulk dispatch of notices with its progress.
 *
 * @property string $channel
 * @property string $status
 * @property int $total
 * @property int $sent
 * @property int $failed
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property string|null $created_by
 */
final class Batch extends BaseEnterpriseModel
{
    protected $table = 'dc_batches';

    protected function casts(): array
    {
        return ['total' => 'integer', 'sent' => 'integer', 'failed' => 'integer', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    /**
     * @return HasMany<Notice, $this>
     */
    public function notices(): HasMany
    {
        return $this->hasMany(Notice::class, 'batch_id');
    }
}
