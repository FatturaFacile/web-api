<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Models;

use App\Support\Database\Concerns\ProtectsRecords;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * An audit entry carried by a restored backup (#230: the active ledger is never replaced). `id` is the identifier the
 * entry had in the ledger, so the same entry can appear once per restore; `archive_id` identifies the archived copy and
 * `restore_id` groups the entries of one restore. The chain of a restore can be verified like the active one.
 *
 * @property string $archive_id
 * @property string $restore_id
 * @property string $id
 * @property string $tenant_id
 * @property int|null $sequence
 * @property int $hash_version
 */
final class AuditLedgerArchiveEntry extends Model
{
    use ProtectsRecords;

    protected $table = 'enterprise_audit_ledger_archive';

    /**
     * Microseconds are kept so that entries recorded in the same second keep the order of the hash chain.
     */
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $primaryKey = 'archive_id';

    public $timestamps = false;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    public function assertRecordMutable(string $operation): void
    {
        $violation = $this->recordProtectionViolation($operation);

        if ($violation !== null) {
            throw new LogicException($violation);
        }
    }

    protected function recordProtectionViolation(string $operation): ?string
    {
        return match ($operation) {
            'update' => 'Audit ledger archive records are strictly immutable and cannot be updated.',
            'delete' => 'Audit ledger archive records are strictly immutable and cannot be deleted.',
            default => null,
        };
    }

    protected function casts(): array
    {
        return [
            'event_timestamp' => 'datetime',
            'archived_at' => 'datetime',
            'sequence' => 'integer',
            'key_version' => 'integer',
            'hash_version' => 'integer',
            'payload_before' => 'array',
            'payload_after' => 'array',
            'diff_json' => 'array',
            'changes' => 'array',
            'context' => 'array',
        ];
    }
}
