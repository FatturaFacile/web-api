<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * An audit entry carried by a restored backup. `id` is the identifier the entry had in the ledger, so the same entry can
 * appear once per restore; `archive_id` identifies the archived copy.
 */
final class AuditLedgerArchiveEntry extends Model
{
    protected $table = 'enterprise_audit_ledger_archive';

    /**
     * Microseconds are kept so that entries recorded in the same second keep the order of the hash chain. The record
     * hash itself formats the timestamp to the second, so chains recorded before keep verifying.
     */
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $primaryKey = 'archive_id';

    public $timestamps = false;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected static function boot(): void
    {
        parent::boot();

        self::updating(function (): void {
            throw new LogicException('Audit ledger archive records are strictly immutable and cannot be updated.');
        });

        self::deleting(function (): void {
            throw new LogicException('Audit ledger archive records are strictly immutable and cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'event_timestamp' => 'datetime',
            'archived_at' => 'datetime',
            'payload_before' => 'array',
            'payload_after' => 'array',
            'diff_json' => 'array',
        ];
    }
}
