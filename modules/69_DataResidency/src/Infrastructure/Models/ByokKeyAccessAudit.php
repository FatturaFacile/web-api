<?php

declare(strict_types=1);

namespace Modules\DataResidency\Infrastructure\Models;

use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use LogicException;

/**
 * One link of the append-only, hash-chained KMS access log (SHA-256 over the previous hash and this entry).
 *
 * @property string $id
 * @property string $kms_configuration_id
 * @property string $operation
 * @property string|null $key_version
 * @property string $outcome
 * @property int $latency_ms
 * @property string $previous_entry_hash
 * @property string $entry_hash
 * @property Carbon $occurred_at
 */
final class ByokKeyAccessAudit extends Model
{
    use BelongsToTenant;

    protected $table = 'core_byok_key_access_audit';

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    public $timestamps = false;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected static function booted(): void
    {
        self::creating(static function (self $record): void {
            if (empty($record->id)) {
                $record->id = (string) Str::uuid7();
            }
        });
        self::updating(static fn () => throw new LogicException('ByokKeyAccessAudit records are append-only and cannot be updated.'));
        self::deleting(static fn () => throw new LogicException('ByokKeyAccessAudit records are append-only and cannot be deleted.'));
    }

    public function delete(): ?bool
    {
        throw new LogicException('ByokKeyAccessAudit records are append-only and cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new LogicException('ByokKeyAccessAudit records are append-only and cannot be deleted.');
    }

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'latency_ms' => 'integer'];
    }
}
