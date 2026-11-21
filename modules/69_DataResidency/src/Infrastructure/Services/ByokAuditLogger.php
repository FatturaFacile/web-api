<?php

declare(strict_types=1);

namespace Modules\DataResidency\Infrastructure\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Modules\DataResidency\Domain\Enums\AccessOutcome;
use Modules\DataResidency\Domain\Enums\ActorType;
use Modules\DataResidency\Domain\Enums\KeyOperation;
use Modules\DataResidency\Infrastructure\Models\ByokKeyAccessAudit;
use Modules\DataResidency\Infrastructure\Models\CustomerManagedKeyEnvelope;

/**
 * Appends to the per-tenant, hash-chained KMS access log: every entry commits to the previous one (SHA-256), so a removed
 * or edited entry breaks the chain and verifyChain() names where.
 */
final class ByokAuditLogger
{
    public const string GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    public function record(
        CustomerManagedKeyEnvelope $envelope,
        KeyOperation $operation,
        AccessOutcome $outcome,
        int $latencyMs = 0,
        ActorType $actorType = ActorType::System,
        ?string $actorId = null,
        ?string $sourceIp = null,
    ): ByokKeyAccessAudit {
        $tenantId = (string) $envelope->tenant_id;

        return Cache::lock('data-residency:audit-chain:'.$tenantId, 10)->block(5, function () use ($envelope, $tenantId, $operation, $outcome, $latencyMs, $actorType, $actorId, $sourceIp): ByokKeyAccessAudit {
            $previous = ByokKeyAccessAudit::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)
                ->orderByDesc('occurred_at')->orderByDesc('id')->value('entry_hash');

            $entry = new ByokKeyAccessAudit([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'legal_entity_id' => $envelope->legal_entity_id,
                'kms_configuration_id' => $envelope->id,
                'operation' => $operation->value,
                'key_version' => $envelope->current_key_version,
                'actor_type' => $actorType->value,
                'actor_id' => $actorId,
                'source_node_code' => (string) config('data_residency.source_node_code', gethostname() ?: 'unknown'),
                'source_ip' => $sourceIp ?? (app()->runningInConsole() ? null : request()->ip()),
                'outcome' => $outcome->value,
                'latency_ms' => $latencyMs,
                'previous_entry_hash' => is_string($previous) ? $previous : self::GENESIS,
                'occurred_at' => Carbon::now(),
            ]);
            $entry->entry_hash = self::hashOf($entry);
            $entry->save();

            return $entry;
        });
    }

    /**
     * @return array{valid: bool, checked: int, broken_at: string|null}
     */
    public function verifyChain(string $tenantId, ?Carbon $since = null): array
    {
        $expectedPrevious = null;
        $checked = 0;

        $rows = ByokKeyAccessAudit::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)
            ->orderBy('occurred_at')->orderBy('id')->cursor();

        foreach ($rows as $row) {
            $inWindow = $since === null || $row->occurred_at->greaterThanOrEqualTo($since);

            if (($expectedPrevious !== null && $row->previous_entry_hash !== $expectedPrevious) || ($expectedPrevious === null && $row->previous_entry_hash !== self::GENESIS)
                || ! hash_equals(self::hashOf($row), $row->entry_hash)) {
                return ['valid' => false, 'checked' => $checked, 'broken_at' => $row->id];
            }

            $expectedPrevious = $row->entry_hash;
            $checked += $inWindow ? 1 : 0;
        }

        return ['valid' => true, 'checked' => $checked, 'broken_at' => null];
    }

    public static function hashOf(ByokKeyAccessAudit $entry): string
    {
        return hash('sha256', implode('|', [
            $entry->previous_entry_hash, $entry->id, (string) $entry->tenant_id, (string) $entry->legal_entity_id, $entry->kms_configuration_id,
            $entry->operation, (string) $entry->key_version, $entry->actor_type, (string) $entry->actor_id, $entry->source_node_code,
            (string) $entry->source_ip, $entry->outcome, (string) $entry->latency_ms, $entry->occurred_at->format('Y-m-d\TH:i:s.u'),
        ]));
    }
}
