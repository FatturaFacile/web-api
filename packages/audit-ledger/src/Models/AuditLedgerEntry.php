<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class AuditLedgerEntry extends Model
{
    protected $table = 'enterprise_audit_ledger';

    public $timestamps = false;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected static function boot(): void
    {
        parent::boot();

        self::updating(function (): void {
            throw new LogicException('Audit ledger records are strictly immutable and cannot be updated.');
        });

        self::deleting(function (): void {
            throw new LogicException('Audit ledger records are strictly immutable and cannot be deleted.');
        });
    }

    public function delete(): ?bool
    {
        throw new LogicException('Audit ledger records are strictly immutable and cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new LogicException('Audit ledger records are strictly immutable and cannot be deleted.');
    }

    protected function casts(): array
    {
        return [
            'event_timestamp' => 'datetime',
            'payload_before' => 'array',
            'payload_after' => 'array',
            'diff_json' => 'array',
        ];
    }
}
