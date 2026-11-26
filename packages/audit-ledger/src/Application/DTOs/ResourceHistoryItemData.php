<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Application\DTOs;

/**
 * One line of the history of a record: what happened, when, by whom and which attributes changed.
 */
final readonly class ResourceHistoryItemData
{
    /**
     * @param  list<string>  $changedFields
     * @param  array{old: array<string, mixed>, new: array<string, mixed>}  $changes
     */
    public function __construct(
        public string $id,
        public int $sequence,
        public string $occurredAt,
        public string $action,
        public ?string $category,
        public ?string $displayActor,
        public ?string $displayImpersonator,
        public ?string $channel,
        public array $changedFields,
        public array $changes,
    ) {}

    public static function fromEntry(AuditEntryData $entry): self
    {
        return new self(
            id: $entry->id,
            sequence: $entry->sequence,
            occurredAt: $entry->occurredAt,
            action: $entry->action,
            category: $entry->category,
            displayActor: $entry->displayActor,
            displayImpersonator: $entry->displayImpersonator,
            channel: isset($entry->context['channel']) ? (string) $entry->context['channel'] : null,
            changedFields: array_values(array_unique([...array_keys($entry->changes['old']), ...array_keys($entry->changes['new'])])),
            changes: $entry->changes,
        );
    }
}
