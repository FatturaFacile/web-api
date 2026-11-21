<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Domain\Audit\Enums\AuditEventType;
use App\Models\OfflineAuditEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Receives the local audit queue of a desktop or mobile device. Entries are idempotent per (tenant, device, sequence):
 * a replay is acknowledged, an entry with the same sequence but another hash is a conflict, and a gap or a broken
 * previous_hash link rejects the whole batch. Every accepted entry is also appended to the server's audit chain, with
 * its offline origin, device and the device hashes in the context.
 */
final class OfflineAuditIngestService
{
    public function __construct(private readonly AuditTrailRecorder $audit) {}

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return list<array{sequence_number: int, id: string, server_received_at: string}>
     *
     * @throws ValidationException
     */
    public function ingest(string $tenantId, string $deviceId, string $origin, ?string $batchId, array $entries): array
    {
        usort($entries, static fn (array $a, array $b): int => (int) $a['sequence_number'] <=> (int) $b['sequence_number']);

        return DB::transaction(function () use ($tenantId, $deviceId, $origin, $batchId, $entries): array {
            $now = Carbon::now('UTC');
            $acks = [];
            $previous = null;

            foreach ($entries as $entry) {
                $sequence = (int) $entry['sequence_number'];
                $existing = $this->slot($tenantId, $deviceId, $sequence)->first();

                if ($existing instanceof OfflineAuditEntry) {
                    if ($existing->record_hash !== $entry['record_hash']) {
                        throw ValidationException::withMessages(['entries' => "Sequence {$sequence} of device {$deviceId} was already received with a different hash."]);
                    }

                    $acks[] = $this->ack($existing);
                    $previous = $existing;

                    continue;
                }

                $predecessor = $previous?->sequence_number === $sequence - 1 ? $previous : $this->slot($tenantId, $deviceId, $sequence - 1)->first();

                if ($sequence > 1 && ! $predecessor instanceof OfflineAuditEntry) {
                    throw ValidationException::withMessages(['entries' => "Sequence {$sequence} of device {$deviceId} has no predecessor: the chain has a gap."]);
                }

                if ($predecessor instanceof OfflineAuditEntry && $predecessor->record_hash !== $entry['previous_hash']) {
                    throw ValidationException::withMessages(['entries' => "Sequence {$sequence} of device {$deviceId} does not link to the previous record hash."]);
                }

                $log = $this->audit->record(
                    event: AuditEventType::OfflineSynced,
                    entityType: (string) $entry['entity_type'],
                    entityId: isset($entry['entity_id']) ? (string) $entry['entity_id'] : null,
                    tenantId: $tenantId,
                    changedFields: [],
                    context: [
                        'origin' => 'offline',
                        'client_origin' => $origin,
                        'device_id' => $deviceId,
                        'offline_action' => (string) $entry['action'],
                        'offline_sequence' => $sequence,
                        'offline_occurred_at' => (string) $entry['occurred_at'],
                        'offline_record_hash' => (string) $entry['record_hash'],
                        'received_at' => $now->toIso8601String(),
                    ],
                );

                $stored = OfflineAuditEntry::query()->create([
                    'tenant_id' => $tenantId,
                    'device_id' => $deviceId,
                    'origin' => $origin,
                    'sequence_number' => $sequence,
                    'previous_hash' => $entry['previous_hash'],
                    'payload_hash' => $entry['payload_hash'],
                    'record_hash' => $entry['record_hash'],
                    'occurred_at' => (string) $entry['occurred_at'],
                    'entity_type' => $entry['entity_type'],
                    'entity_id' => $entry['entity_id'] ?? null,
                    'action' => $entry['action'],
                    'payload' => $this->encode($entry['payload'] ?? $entry['payload_json'] ?? null),
                    'metadata' => $this->encode($entry['metadata'] ?? $entry['metadata_json'] ?? null),
                    'batch_id' => $batchId,
                    'audit_log_id' => (string) $log->getKey(),
                    'received_at' => $now,
                ]);

                $acks[] = $this->ack($stored);
                $previous = $stored;
            }

            return $acks;
        });
    }

    /**
     * @return Builder<OfflineAuditEntry>
     */
    private function slot(string $tenantId, string $deviceId, int $sequence): Builder
    {
        return OfflineAuditEntry::query()->where('tenant_id', $tenantId)->where('device_id', $deviceId)->where('sequence_number', $sequence);
    }

    /**
     * @return array{sequence_number: int, id: string, server_received_at: string}
     */
    private function ack(OfflineAuditEntry $entry): array
    {
        return [
            'sequence_number' => $entry->sequence_number,
            'id' => $entry->id,
            'server_received_at' => $entry->received_at->toIso8601String(),
        ];
    }

    private function encode(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return is_string($value) ? $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
