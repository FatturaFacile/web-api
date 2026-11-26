<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An asynchronous export package of the ledger: CSV + JSON + a PDF summary and a manifest with the sequence bounds, the
 * hashes at both ends, the verification result, the SHA-256 of the package and its signature (Mod 144).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $requested_by
 * @property string|null $idempotency_key
 * @property string $status queued|running|completed|failed
 * @property Carbon $from_date
 * @property Carbon $to_date
 * @property array<string, mixed>|null $filters
 * @property int|null $from_sequence
 * @property int|null $to_sequence
 * @property string|null $start_hash
 * @property string|null $end_hash
 * @property int|null $entries_count
 * @property string|null $verification_outcome
 * @property string|null $disk
 * @property string|null $path
 * @property string|null $sha256
 * @property string|null $signature
 * @property string|null $error
 * @property Carbon|null $completed_at
 */
final class AuditExport extends Model
{
    public const string STATUS_QUEUED = 'queued';

    public const string STATUS_RUNNING = 'running';

    public const string STATUS_COMPLETED = 'completed';

    public const string STATUS_FAILED = 'failed';

    protected $table = 'audit_exports';

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'from_date' => 'date',
            'to_date' => 'date',
            'filters' => 'array',
            'from_sequence' => 'integer',
            'to_sequence' => 'integer',
            'entries_count' => 'integer',
            'completed_at' => 'datetime',
        ];
    }
}
