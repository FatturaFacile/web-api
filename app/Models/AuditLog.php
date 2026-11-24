<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * One record of the immutable audit trail. INSERT-only: the model refuses updates and deletes, and the database
 * triggers refuse them again whoever connects. The tenant is filtered explicitly by the readers (the trail also holds
 * system level events with a null tenant), like the enterprise audit ledger.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property int $sequence_number
 * @property string $event_type
 * @property string $auditable_type
 * @property string|null $auditable_id
 * @property string $actor_type
 * @property string|null $actor_id
 * @property string|null $actor_email
 * @property string|null $actor_role
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $request_id
 * @property string|null $route_name
 * @property string|null $old_values_encrypted
 * @property string|null $new_values_encrypted
 * @property list<string>|null $changed_fields
 * @property array<string, mixed>|null $context_metadata
 * @property string $previous_hash
 * @property string $current_hash
 * @property string $hmac_signature
 * @property string $occurred_at
 */
class AuditLog extends Model
{
    use HasUuids;

    protected $table = 'audit_logs';

    public $timestamps = false;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Audit logs are strictly immutable and cannot be updated.');
        });

        static::deleting(static function (): never {
            throw new LogicException('Audit logs are strictly immutable and cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'sequence_number' => 'integer',
            'changed_fields' => 'array',
            'context_metadata' => 'array',
        ];
    }

    /**
     * @return HasOne<AuditWormBatchEntry, $this>
     */
    public function wormEntry(): HasOne
    {
        return $this->hasOne(AuditWormBatchEntry::class, 'audit_log_id');
    }
}
