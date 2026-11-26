<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Models;

use App\Models\User;
use App\Support\Database\Concerns\ProtectsRecords;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One entry of the probative audit chain of a tenant. Append only: the model refuses every update and delete (also inside
 * Immutability::lifted(), which never applies to the ledger) and production grants INSERT and SELECT only on the table.
 *
 * `record_hash` is the `hash` of the entry; `event_timestamp` is its `occurred_at` (UTC, microseconds).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property int|null $sequence
 * @property string|null $category
 * @property string $module_code
 * @property string $action
 * @property string $resource_type
 * @property string $resource_id
 * @property string|null $actor_user_id
 * @property string $actor_type
 * @property string|null $actor_ref
 * @property string|null $impersonator_ref
 * @property string|null $channel
 * @property string|null $request_id
 * @property array<string, mixed>|null $changes
 * @property array<string, mixed>|null $context
 * @property array<string, mixed>|null $diff_json
 * @property array<string, mixed>|null $payload_before
 * @property array<string, mixed>|null $payload_after
 * @property int|null $key_version
 * @property int $hash_version
 * @property string $record_hash
 * @property string $previous_hash
 * @property Carbon $event_timestamp
 */
final class AuditLedgerEntry extends Model
{
    use ProtectsRecords;

    protected $table = 'enterprise_audit_ledger';

    /**
     * Microseconds are kept so that entries recorded in the same second keep the order of the hash chain. The legacy
     * record hash formats the timestamp to the second, so chains recorded before keep verifying.
     */
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    public $timestamps = false;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /**
     * The ledger is never lifted: the guard of the trait is applied without the global Immutability switch.
     */
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
            'update' => 'Audit ledger records are strictly immutable and cannot be updated.',
            'delete' => 'Audit ledger records are strictly immutable and cannot be deleted.',
            default => null,
        };
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

    /**
     * @return array<string, mixed>|null
     */
    public function getOldValuesAttribute(): ?array
    {
        return $this->payload_before;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getNewValuesAttribute(): ?array
    {
        return $this->payload_after;
    }

    /**
     * The values before the change. Entries written before the forensic extension hold them in payload_before; newer ones
     * hold only the attributes that changed, in `changes.old`, and are read from there.
     *
     * @return Attribute<array<string, mixed>|null, never>
     */
    protected function payloadBefore(): Attribute
    {
        return Attribute::get(fn (mixed $value, array $attributes): ?array => $this->sideOf($value, $attributes, 'old'));
    }

    /**
     * @return Attribute<array<string, mixed>|null, never>
     */
    protected function payloadAfter(): Attribute
    {
        return Attribute::get(fn (mixed $value, array $attributes): ?array => $this->sideOf($value, $attributes, 'new'));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>|null
     */
    private function sideOf(mixed $value, array $attributes, string $side): ?array
    {
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : null;
        }

        $changes = $attributes['changes'] ?? null;
        $changes = is_string($changes) ? json_decode($changes, true) : $changes;
        $part = is_array($changes) ? ($changes[$side] ?? null) : null;

        return is_array($part) && $part !== [] ? $part : null;
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
