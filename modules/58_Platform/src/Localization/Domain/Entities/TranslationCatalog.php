<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Domain\Entities;

use Modules\Platform\Localization\Domain\Events\TranslationsChanged;
use Modules\Platform\Localization\Domain\Exceptions\LockedTranslationException;
use Modules\Platform\Localization\Domain\ValueObjects\Locale;
use Modules\Platform\Localization\Domain\ValueObjects\TranslationKey;

/**
 * Aggregate root of the translations of one locale in one scope: the tenant (tenant_id) or the system (null). A catalog
 * loaded for a tenant also knows which system keys are locked: those can never be overridden through a tenant catalog.
 * Changes are recorded as domain events and published by the application layer after the catalog is persisted.
 */
final class TranslationCatalog
{
    /** @var array<string, TranslationEntry> */
    private array $entries = [];

    /** @var array<string, true> */
    private array $lockedSystemKeys = [];

    /** @var array<string, TranslationEntry> */
    private array $changed = [];

    /** @var list<object> */
    private array $events = [];

    /**
     * @param  list<TranslationEntry>  $entries
     * @param  list<string>  $lockedSystemKeys  keys the system dictionary locks (relevant for tenant catalogs)
     */
    public function __construct(
        public readonly ?string $tenantId,
        public readonly Locale $locale,
        array $entries = [],
        array $lockedSystemKeys = [],
    ) {
        foreach ($entries as $entry) {
            $this->entries[$entry->key->value()] = $entry;
        }

        foreach ($lockedSystemKeys as $key) {
            $this->lockedSystemKeys[$key] = true;
        }
    }

    public function isSystem(): bool
    {
        return $this->tenantId === null;
    }

    public function entry(TranslationKey $key): ?TranslationEntry
    {
        return $this->entries[$key->value()] ?? null;
    }

    /**
     * @return list<TranslationEntry>
     */
    public function entries(): array
    {
        return array_values($this->entries);
    }

    public function isLocked(TranslationKey $key): bool
    {
        return isset($this->lockedSystemKeys[$key->value()])
            || ($this->entries[$key->value()]->isLocked ?? false);
    }

    /**
     * Creates or changes an entry.
     *
     * @throws LockedTranslationException when the key is locked
     */
    public function upsert(TranslationKey $key, string $value): TranslationEntry
    {
        if ($this->isLocked($key)) {
            throw new LockedTranslationException($key->value());
        }

        $existing = $this->entries[$key->value()] ?? null;
        $entry = $existing?->withValue($value) ?? new TranslationEntry($key, $value);

        if ($existing === null || $existing->value() !== $value) {
            $this->entries[$key->value()] = $entry;
            $this->changed[$key->value()] = $entry;
            $this->events[] = new TranslationsChanged($this->tenantId, $this->locale->value());
        }

        return $entry;
    }

    /**
     * The entries changed since the catalog was loaded.
     *
     * @return list<TranslationEntry>
     */
    public function changedEntries(): array
    {
        return array_values($this->changed);
    }

    /**
     * Returns and clears the recorded domain events (one per effective change set).
     *
     * @return list<object>
     */
    public function pullEvents(): array
    {
        $events = $this->events === [] ? [] : [$this->events[0]];
        $this->events = [];

        return $events;
    }
}
