<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Application\DTOs;

use App\Models\User;
use Enterprise\AuditLedger\Domain\Enums\ActorType;
use Enterprise\AuditLedger\Domain\Enums\AuditCategory;
use Enterprise\AuditLedger\Domain\Services\CanonicalSerializer;
use Enterprise\AuditLedger\Domain\Services\DiffBuilder;
use Enterprise\AuditLedger\Domain\Services\Pseudonymizer;
use Enterprise\AuditLedger\Models\AuditLedgerArchiveEntry;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Illuminate\Support\Collection;

/**
 * A ledger entry as the API shows it: pseudonyms resolved to readable values (or the erased label), legacy payloads turned
 * into the same {old, new} changes document, the truncated IP and the user agent merged into the context.
 */
final readonly class AuditEntryData
{
    /**
     * @param  array{old: array<string, mixed>, new: array<string, mixed>}  $changes
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public string $id,
        public int $sequence,
        public string $occurredAt,
        public string $tenantId,
        public string $companyId,
        public ?string $category,
        public string $moduleCode,
        public string $action,
        public string $resourceType,
        public string $resourceId,
        public string $actorType,
        public ?string $actorRef,
        public ?string $displayActor,
        public ?string $impersonatorRef,
        public ?string $displayImpersonator,
        public array $changes,
        public array $context,
        public string $previousHash,
        public string $hash,
        public ?int $keyVersion,
        public int $hashVersion,
    ) {}

    /**
     * The tokens and user ids a page of entries needs resolved, in one batch.
     *
     * @param  iterable<AuditLedgerEntry|AuditLedgerArchiveEntry>  $entries
     * @return array{tokens: array<string, string>, users: array<string, string>}
     */
    public static function resolutions(string $tenantId, iterable $entries, Pseudonymizer $pseudonymizer): array
    {
        $tokens = [];
        $legacyUserIds = [];

        foreach ($entries as $entry) {
            array_push($tokens, ...Pseudonymizer::tokensIn([$entry->actor_ref, $entry->impersonator_ref, $entry->getAttribute('changes')]));

            if ($entry->actor_ref === null && $entry->actor_user_id !== null) {
                $legacyUserIds[] = (string) $entry->actor_user_id;
            }
        }

        $users = $legacyUserIds === [] ? [] : User::query()->whereIn('id', array_unique($legacyUserIds))->pluck('name', 'id')
            ->map(static fn (mixed $name): string => (string) $name)->all();

        return ['tokens' => $pseudonymizer->resolveMany($tenantId, $tokens), 'users' => $users];
    }

    /**
     * @param  array{tokens: array<string, string>, users: array<string, string>}  $resolved
     */
    public static function fromEntry(AuditLedgerEntry|AuditLedgerArchiveEntry $entry, array $resolved): self
    {
        $stored = $entry->getAttribute('changes');
        $changes = is_array($stored) ? $stored : (new DiffBuilder)->build($entry->payload_before, $entry->payload_after);
        /** @var array{old: array<string, mixed>, new: array<string, mixed>} $readable */
        $readable = Pseudonymizer::applyTo(['old' => (array) ($changes['old'] ?? []), 'new' => (array) ($changes['new'] ?? [])], $resolved['tokens']);

        $context = is_array($entry->context) ? $entry->context : [];
        $context['channel'] ??= $entry->channel;
        $context['request_id'] ??= $entry->request_id;
        $context['ip_address'] = $entry->ip_address;
        $context['user_agent'] = $entry->user_agent;

        $display = $entry->actor_ref !== null
            ? ($resolved['tokens'][$entry->actor_ref] ?? Pseudonymizer::ERASED_LABEL)
            : ($entry->actor_user_id !== null ? ($resolved['users'][(string) $entry->actor_user_id] ?? Pseudonymizer::ERASED_LABEL) : null);

        return new self(
            id: (string) $entry->id,
            sequence: (int) $entry->sequence,
            occurredAt: CanonicalSerializer::timestamp($entry->event_timestamp),
            tenantId: (string) $entry->tenant_id,
            companyId: (string) $entry->company_id,
            category: $entry->category ?? AuditCategory::infer((string) $entry->action, (string) $entry->module_code)->value,
            moduleCode: (string) $entry->module_code,
            action: (string) $entry->action,
            resourceType: (string) $entry->resource_type,
            resourceId: (string) $entry->resource_id,
            actorType: ActorType::fromStored($entry->actor_type)->value,
            actorRef: $entry->actor_ref,
            displayActor: $display,
            impersonatorRef: $entry->impersonator_ref,
            displayImpersonator: $entry->impersonator_ref !== null ? ($resolved['tokens'][$entry->impersonator_ref] ?? Pseudonymizer::ERASED_LABEL) : null,
            changes: $readable,
            context: $context,
            previousHash: (string) $entry->previous_hash,
            hash: (string) $entry->record_hash,
            keyVersion: $entry->key_version,
            hashVersion: (int) $entry->hash_version,
        );
    }

    /**
     * @param  iterable<AuditLedgerEntry|AuditLedgerArchiveEntry>  $entries
     * @return Collection<int, self>
     */
    public static function collect(string $tenantId, iterable $entries, Pseudonymizer $pseudonymizer): Collection
    {
        $entries = $entries instanceof Collection ? $entries : collect($entries);
        $resolved = self::resolutions($tenantId, $entries, $pseudonymizer);

        return $entries->map(static fn (AuditLedgerEntry|AuditLedgerArchiveEntry $entry): self => self::fromEntry($entry, $resolved))->values();
    }
}
