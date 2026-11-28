<?php

declare(strict_types=1);

namespace Modules\TimeTravel\Models;

use App\Support\Database\Concerns\ProtectsRecords;
use Enterprise\Foundation\Concerns\HasUuidV7;
use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * One immutable fact of the bi-temporal event store (GeBüV / Art. 957a CO): economically effective at valid_at, known to the system
 * from recorded_at, sealed into the SHA-256 hash chain of its stream. It can be created and never changed or deleted.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $stream_id
 * @property string $aggregate_type
 * @property string $aggregate_id
 * @property int $sequence_number
 * @property string $event_type
 * @property string $valid_at UTC, Y-m-d H:i:s.u
 * @property string $recorded_at UTC, Y-m-d H:i:s.u
 * @property array<string, mixed> $payload
 * @property array<string, mixed>|null $metadata
 * @property string|null $actor_user_id
 * @property string $previous_event_hash
 * @property string $cryptographic_chain_hash
 */
final class BiTemporalEvent extends Model
{
    use BelongsToTenant;
    use HasUuidV7;
    use ProtectsRecords;

    protected $table = 'es_bitemporal_event_streams';

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sequence_number' => 'integer',
            'payload' => 'array',
            'metadata' => 'array',
        ];
    }

    protected function recordProtectionViolation(string $operation): ?string
    {
        return $operation === 'create' ? null : 'Bi-temporal event store records are strictly immutable and cannot be updated or deleted.';
    }
}
