<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Application\Commands;

use App\Support\Database\TransactionLock;
use Enterprise\AuditLedger\Domain\AuditChain;
use Enterprise\AuditLedger\Domain\Enums\AuditCategory;
use Enterprise\AuditLedger\Domain\Events\AuditEntryAppended;
use Enterprise\AuditLedger\Domain\Services\AuditKeyring;
use Enterprise\AuditLedger\Domain\Services\CanonicalSerializer;
use Enterprise\AuditLedger\Domain\Services\ChainHasher;
use Enterprise\AuditLedger\Domain\Services\DiffBuilder;
use Enterprise\AuditLedger\Domain\Services\PayloadSanitizer;
use Enterprise\AuditLedger\Domain\ValueObjects\ClientContext;
use Enterprise\AuditLedger\Events\AuditRecordedEvent;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Enterprise\AuditLedger\Support\ActorResolver;
use Enterprise\AuditLedger\Support\AuditIds;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Appends an entry to the hash chain of a tenant. The append runs in a transaction that joins the one of the caller
 * (savepoint when nested) under the per-tenant TransactionLock, so the sequence has no gap and no duplicate, and a
 * rollback of the business transaction leaves no orphan entry. A failure is thrown: callers fail closed.
 */
final class AppendAuditEntryHandler
{
    private const int MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly ChainHasher $hasher,
        private readonly AuditKeyring $keyring,
        private readonly DiffBuilder $diffs,
        private readonly PayloadSanitizer $sanitizer,
        private readonly ActorResolver $actors,
    ) {}

    public function handle(AppendAuditEntryCommand $command): AuditLedgerEntry
    {
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                return DB::transaction(fn (): AuditLedgerEntry => $this->append($command));
            } catch (UniqueConstraintViolationException $e) {
                // Another writer took the sequence between the read of the tail and the insert: read the tail again.
                if ($attempt >= self::MAX_ATTEMPTS) {
                    throw $e;
                }
            }
        }
    }

    private function append(AppendAuditEntryCommand $command): AuditLedgerEntry
    {
        $tenantId = $command->tenantId;
        $resourceId = AuditIds::uuidFor($command->resourceId);

        TransactionLock::acquire("audit_tenant_{$tenantId}");

        $tail = AuditLedgerEntry::query()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('sequence')
            ->orderByDesc('sequence')
            ->lockForUpdate()
            ->first();

        $chain = AuditChain::resume($tenantId, $this->hasher, $tail?->sequence, $tail?->record_hash, $tail?->event_timestamp);
        $keyVersion = $this->keyring->activeVersion($tenantId);

        $changes = $this->changes($command, $resourceId);
        $actor = $this->actors->resolve($tenantId, $command->actorUserId, $command->actorType);
        $client = $command->client ?? ClientContext::fromRequest(app()->bound('request') ? app('request') : null);
        $category = $command->category ?? AuditCategory::infer($command->action->value, $command->moduleCode);

        $context = $client->hashedPart() + CanonicalSerializer::canonicalize($command->context);

        if ($resourceId !== strtolower($command->resourceId)) {
            $context['resource_ref'] = substr($command->resourceId, 0, 120);
        }

        $row = $chain->append([
            'id' => (string) Str::uuid7(),
            'company_id' => $command->companyId,
            'category' => $category->value,
            'module_code' => $command->moduleCode,
            'action' => $command->action->value,
            'resource_type' => $command->resourceType,
            'resource_id' => $resourceId,
            'actor_user_id' => $actor->userId,
            'actor_type' => $actor->type->value,
            'actor_ref' => $actor->ref,
            'impersonator_ref' => $actor->impersonatorRef,
            'channel' => $client->channel,
            'request_id' => $client->requestId,
            'changes' => $changes,
            'context' => CanonicalSerializer::canonicalize($context),
            'ip_address' => $client->ipAddress,
            'user_agent' => $client->userAgent,
        ], $keyVersion);

        $entry = AuditLedgerEntry::query()->create($row);

        AuditEntryAppended::dispatch((string) $entry->id, $tenantId, (int) $entry->sequence, $command->action->value, $category->value, (string) $entry->record_hash);
        AuditRecordedEvent::dispatch((string) $entry->id, $tenantId, $command->action->value, $command->resourceType, $resourceId, (string) $entry->record_hash);

        return $entry;
    }

    /**
     * The canonical `changes` document: exclusions applied, the diff computed on the raw values, then secrets removed and
     * personal values replaced by tokens.
     *
     * @return array{old: array<string, mixed>, new: array<string, mixed>}
     */
    private function changes(AppendAuditEntryCommand $command, string $resourceId): array
    {
        $before = $this->excluding($command->before, $command->excludedAttributes);
        $after = $this->excluding($command->after, $command->excludedAttributes);

        // The touched `updated_at` is not a change of the record: it only stays when it is the only thing that changed.
        if ($command->action->value === 'UPDATED' && is_array($after) && array_diff(array_keys($after), ['updated_at']) !== []) {
            unset($after['updated_at']);
        }
        $diff = $this->diffs->build($before, $after);

        $changes = [
            'old' => $this->sanitizer->sanitize($command->tenantId, $command->resourceType, $resourceId, $diff['old'], $command->personalAttributes),
            'new' => $this->sanitizer->sanitize($command->tenantId, $command->resourceType, $resourceId, $diff['new'], $command->personalAttributes),
        ];

        /** @var array{old: array<string, mixed>, new: array<string, mixed>} */
        return CanonicalSerializer::canonicalize($changes);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @param  list<string>  $excluded
     * @return array<string, mixed>|null
     */
    private function excluding(?array $payload, array $excluded): ?array
    {
        if ($payload === null || $excluded === []) {
            return $payload;
        }

        return array_diff_key($payload, array_flip($excluded));
    }
}
