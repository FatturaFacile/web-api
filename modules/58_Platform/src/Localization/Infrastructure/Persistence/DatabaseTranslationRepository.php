<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Infrastructure\Persistence;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Platform\Localization\Domain\Contracts\TranslationCatalogRepository;
use Modules\Platform\Localization\Domain\Contracts\TranslationLookup;
use Modules\Platform\Localization\Domain\Entities\TranslationCatalog;
use Modules\Platform\Localization\Domain\Entities\TranslationEntry;
use Modules\Platform\Localization\Domain\ValueObjects\Locale;
use Modules\Platform\Localization\Domain\ValueObjects\TranslationKey;

/**
 * Stores the translations in the `translations` table. The tenant is always filtered explicitly (null addresses the
 * system dictionary): no global scope can widen or narrow a query by accident.
 */
final class DatabaseTranslationRepository implements TranslationCatalogRepository, TranslationLookup
{
    public function __construct(private readonly Dispatcher $events) {}

    public function find(?string $tenantId, string $locale, string $key): ?string
    {
        $value = $this->scope($tenantId, $locale)->where('key', $key)->value('value');

        return $value === null ? null : (string) $value;
    }

    public function load(?string $tenantId, Locale $locale, array $keys): TranslationCatalog
    {
        $names = array_map(static fn (TranslationKey $key): string => $key->value(), $keys);
        $entries = [];
        $locked = [];

        foreach (array_chunk($names, 500) as $chunk) {
            foreach ($this->scope($tenantId, $locale->value())->whereIn('key', $chunk)->get(['key', 'value', 'is_locked', 'is_deprecated']) as $row) {
                $entries[] = new TranslationEntry(TranslationKey::from((string) $row->key), (string) $row->value, (bool) $row->is_locked, (bool) $row->is_deprecated);
            }

            if ($tenantId !== null) {
                $rows = DB::table('translations')->whereNull('tenant_id')->where('is_locked', true)->whereIn('key', $chunk)->distinct()->pluck('key');
                $locked = array_merge($locked, array_map('strval', $rows->all()));
            }
        }

        return new TranslationCatalog($tenantId, $locale, $entries, $locked);
    }

    public function save(TranslationCatalog $catalog): void
    {
        $changed = $catalog->changedEntries();

        if ($changed === []) {
            return;
        }

        DB::transaction(function () use ($catalog, $changed): void {
            $now = now();

            foreach ($changed as $entry) {
                $identity = ['tenant_id' => $catalog->tenantId, 'locale' => $catalog->locale->value(), 'key' => $entry->key->value()];

                $exists = DB::table('translations')->where($identity)->exists();

                if ($exists) {
                    DB::table('translations')->where($identity)->update(['value' => $entry->value(), 'group' => $entry->group(), 'is_deprecated' => $entry->isDeprecated, 'updated_at' => $now]);
                } else {
                    DB::table('translations')->insert($identity + ['group' => $entry->group(), 'value' => $entry->value(), 'is_locked' => false, 'is_deprecated' => $entry->isDeprecated, 'created_at' => $now, 'updated_at' => $now]);
                }
            }
        });

        foreach ($catalog->pullEvents() as $event) {
            $this->events->dispatch($event);
        }
    }

    /**
     * The key => value map of one scope and locale.
     *
     * @return array<string, string>
     */
    public function dictionary(?string $tenantId, string $locale): array
    {
        $map = [];

        foreach ($this->scope($tenantId, $locale)->get(['key', 'value']) as $row) {
            $map[(string) $row->key] = (string) $row->value;
        }

        return $map;
    }

    private function scope(?string $tenantId, string $locale): Builder
    {
        return DB::table('translations')->where('tenant_id', $tenantId)->where('locale', $locale)->whereNull('deleted_at');
    }
}
