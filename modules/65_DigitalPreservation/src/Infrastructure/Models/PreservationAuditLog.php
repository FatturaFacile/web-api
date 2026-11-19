<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Infrastructure\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\DigitalPreservation\Domain\Exceptions\PreservationImmutableException;

/**
 * Append-only audit entry, chained to the previous entry of the tenant by SHA-256.
 *
 * @property string $id
 * @property string $tenant_id
 * @property int $sequence
 * @property ?string $preservation_batch_id
 * @property ?string $preservation_item_id
 * @property ?string $actor_id
 * @property string $action
 * @property array<string, mixed>|null $details_json
 * @property ?string $sha256_snapshot
 * @property ?string $previous_log_sha256
 * @property string $entry_sha256
 * @property ?string $ip_address
 * @property ?string $user_agent
 * @property Carbon $created_at
 */
class PreservationAuditLog extends Model
{
    use BelongsToTenant, HasUuids;

    public const ?string UPDATED_AT = null;

    protected $fillable = [
        'tenant_id', 'sequence', 'preservation_batch_id', 'preservation_item_id', 'actor_id', 'action', 'details_json',
        'sha256_snapshot', 'previous_log_sha256', 'entry_sha256', 'ip_address', 'user_agent', 'created_at',
    ];

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new PreservationImmutableException('Preservation audit entries are append-only.');
        });

        static::deleting(static function (): void {
            throw new PreservationImmutableException('Preservation audit entries are append-only.');
        });
    }

    protected function casts(): array
    {
        return ['details_json' => 'array', 'sequence' => 'integer', 'created_at' => 'datetime'];
    }
}
