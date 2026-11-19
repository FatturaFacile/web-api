<?php

declare(strict_types=1);

namespace App\Models\Privacy;

use Enterprise\Foundation\Concerns\HasUuidV7;
use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Immutable record of one automated retention run: counts of evaluated, purged, anonymised and statutory-skipped rows.
 */
final class PrivacyRetentionRunLog extends Model
{
    use BelongsToTenant, HasUuidV7;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id', 'tenant_id', 'created_at', 'updated_at'];

    protected $table = 'privacy_retention_run_logs';

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('Retention run logs are immutable.'));
        self::deleting(static fn (): never => throw new LogicException('Retention run logs are immutable.'));
    }

    protected function casts(): array
    {
        return [
            'dry_run' => 'boolean',
            'details' => 'array',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }
}
