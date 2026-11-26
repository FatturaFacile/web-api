<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Models;

use App\Support\Database\Concerns\ProtectsRecords;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The signed state of a tenant chain at a point in time (daily, or at the close of an accounting period). Only the anchor
 * fields can be set afterwards, once the checkpoint has been written to the write-once target.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $type daily|period
 * @property string|null $period_ref
 * @property int $sequence
 * @property string $hash
 * @property string $signature
 * @property int $key_version
 * @property Carbon $created_at
 * @property Carbon|null $anchored_at
 * @property string|null $anchor_uri
 */
final class AuditCheckpoint extends Model
{
    use ProtectsRecords;

    public const string TYPE_DAILY = 'daily';

    public const string TYPE_PERIOD = 'period';

    protected $table = 'audit_checkpoints';

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    public $timestamps = false;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected function recordProtectionViolation(string $operation): ?string
    {
        return match ($operation) {
            'update' => $this->changesOutside(['anchored_at', 'anchor_uri']) ? 'An audit checkpoint is immutable: only its anchor can be set.' : null,
            'delete' => 'An audit checkpoint cannot be deleted.',
            default => null,
        };
    }

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'key_version' => 'integer',
            'created_at' => 'datetime',
            'anchored_at' => 'datetime',
        ];
    }
}
