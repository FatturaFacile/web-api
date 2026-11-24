<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Domain\Audit\DTOs\AuditActor;
use App\Domain\Audit\Enums\AuditEventType;
use App\Models\AuditLog;
use App\Support\Database\TransactionLock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Appends records to the audit trail. Every append runs under a per-tenant lock and a transaction so that the sequence
 * numbers are gapless and every record links to the hash of the one before, even under concurrency.
 */
final class AuditTrailRecorder
{
    public function __construct(
        private readonly AuditChainCryptoService $crypto,
        private readonly AuditActorResolver $actors,
    ) {}

    /**
     * @param  array<string, mixed>|null  $oldValues  already sanitised (see sanitise())
     * @param  array<string, mixed>|null  $newValues  already sanitised (see sanitise())
     * @param  list<string>  $changedFields
     * @param  array<string, mixed>  $context
     */
    public function record(
        AuditEventType $event,
        string $entityType,
        ?string $entityId,
        ?string $tenantId,
        ?array $oldValues = null,
        ?array $newValues = null,
        array $changedFields = [],
        array $context = [],
        ?AuditActor $actor = null,
    ): AuditLog {
        $actor ??= $this->actors->resolve($tenantId);
        $lockName = 'audit_seq:'.($tenantId ?? 'system');

        return Cache::lock($lockName, (int) config('audit.lock_seconds', 10))->block(
            (int) config('audit.lock_seconds', 10),
            fn (): AuditLog => DB::transaction(function () use ($event, $entityType, $entityId, $tenantId, $oldValues, $newValues, $changedFields, $context, $actor): AuditLog {
                TransactionLock::acquire('audit_tenant_'.($tenantId ?? 'system'));

                $chain = fn () => AuditLog::query()->when($tenantId === null, fn ($query) => $query->whereNull('tenant_id'), fn ($query) => $query->where('tenant_id', $tenantId));
                $lastSequence = (int) $chain()->max('sequence_number');
                $lastHash = $lastSequence === 0 ? null : $chain()->where('sequence_number', $lastSequence)->value('current_hash');

                $log = new AuditLog;
                $log->tenant_id = $tenantId;
                $log->sequence_number = $lastSequence + 1;
                $log->event_type = $event->value;
                $log->auditable_type = $entityType;
                $log->auditable_id = $entityId;
                $log->actor_type = $actor->type->value;
                $log->actor_id = $actor->id;
                $log->actor_email = $actor->email;
                $log->actor_role = $actor->role;
                $log->ip_address = $actor->ipAddress;
                $log->user_agent = $actor->userAgent;
                $log->request_id = $actor->requestId;
                $log->route_name = $actor->routeName;
                $log->old_values_encrypted = $this->crypto->encrypt($oldValues);
                $log->new_values_encrypted = $this->crypto->encrypt($newValues);
                $log->changed_fields = $changedFields === [] ? null : $changedFields;
                $log->context_metadata = $context === [] ? null : $context;
                $log->occurred_at = Carbon::now('UTC')->format('Y-m-d H:i:s.u');
                $log->previous_hash = is_string($lastHash) ? $lastHash : AuditChainCryptoService::GENESIS_HASH;
                $log->current_hash = $this->crypto->computeHash($log->previous_hash, $this->crypto->hashableFields($log));
                $log->hmac_signature = $this->crypto->sign($log->current_hash);
                $log->save();

                return $log;
            }),
        );
    }

    /**
     * Drops the excluded attributes and masks the sensitive ones, so the trail never holds a secret or a clear IBAN.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $excluded
     * @param  list<string>  $masked
     * @return array<string, mixed>
     */
    public function sanitise(array $attributes, array $excluded, array $masked): array
    {
        foreach ($excluded as $key) {
            unset($attributes[$key]);
        }

        foreach ($masked as $key) {
            if (array_key_exists($key, $attributes) && $attributes[$key] !== null) {
                $attributes[$key] = $this->mask((string) $attributes[$key]);
            }
        }

        return $attributes;
    }

    /**
     * "CH9300762011623852957" becomes "CH93 **** **** 2957".
     */
    public function mask(string $value): string
    {
        $compact = (string) preg_replace('/\s+/', '', $value);

        if (mb_strlen($compact) <= 8) {
            return '****';
        }

        return mb_substr($compact, 0, 4).' **** **** '.mb_substr($compact, -4);
    }
}
