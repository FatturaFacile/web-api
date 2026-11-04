<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

    protected $appends = [
        'event',
        'auditable_type',
        'auditable_id',
        'old_values',
        'new_values',
        'user_id',
    ];

    public function getEventAttribute(): ?string
    {
        return $this->action;
    }

    public function getAuditableTypeAttribute(): ?string
    {
        return $this->resource_type;
    }

    public function getAuditableIdAttribute(): ?string
    {
        return $this->resource_id;
    }

    public function getOldValuesAttribute(): ?array
    {
        return $this->payload_before;
    }

    public function getNewValuesAttribute(): ?array
    {
        return $this->payload_after;
    }

    public function getUserIdAttribute(): ?string
    {
        return $this->actor_user_id;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
