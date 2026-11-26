<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One verification of a tenant chain (nightly or on demand): queued, running, then completed with an outcome or failed.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $trigger manual|scheduled
 * @property string $status queued|running|completed|failed
 * @property string|null $outcome valid|broken_at|missing_range
 * @property int|null $broken_at_sequence
 * @property int|null $missing_from_sequence
 * @property int|null $missing_to_sequence
 * @property int $verified_entries
 * @property int|null $last_sequence
 * @property string|null $requested_by
 * @property string|null $error
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 */
final class IntegrityVerificationRun extends Model
{
    public const string STATUS_QUEUED = 'queued';

    public const string STATUS_RUNNING = 'running';

    public const string STATUS_COMPLETED = 'completed';

    public const string STATUS_FAILED = 'failed';

    protected $table = 'audit_integrity_runs';

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'broken_at_sequence' => 'integer',
            'missing_from_sequence' => 'integer',
            'missing_to_sequence' => 'integer',
            'verified_entries' => 'integer',
            'last_sequence' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
