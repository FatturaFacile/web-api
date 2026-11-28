<?php

declare(strict_types=1);

namespace Modules\TimeTravel\Models;

use Enterprise\Foundation\Concerns\HasUuidV7;
use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * The folded state of a stream at (valid_at, recorded_at). Derived data: a checkpoint can be regenerated from the events at any time.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $aggregate_type
 * @property string $valid_at
 * @property string $recorded_at
 * @property array<string, mixed> $state_payload
 * @property string $merkle_root
 * @property int $event_count
 * @property int $last_sequence_number
 */
final class MaterializedCheckpoint extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    protected $table = 'es_materialized_checkpoints';

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'state_payload' => 'array',
            'event_count' => 'integer',
            'last_sequence_number' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
