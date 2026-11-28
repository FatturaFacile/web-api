<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Infrastructure\Persistence;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Platform\Localization\Domain\Services\TranslationFallbackResolver;
use Modules\Platform\Localization\Domain\ValueObjects\Locale;
use Modules\Platform\Localization\Infrastructure\Cache\DictionaryCache;

/**
 * Read side of the translation editor: the paginated key list of a locale with the effective value, the origin of that
 * value and the state flags, the missing keys report and the matrix behind the spreadsheet export.
 */
final class TranslationReadModel
{
    public function __construct(
        private readonly DictionaryCache $cache,
        private readonly TranslationFallbackResolver $resolver,
        private readonly DatabaseTranslationRepository $repository,
    ) {}

    /**
     * @return array{rows: list<array<string, mixed>>, total: int, current_page: int, per_page: int, last_page: int, missing_count: int}
     */
    public function page(string $tenantId, Locale $locale, ?string $group, bool $onlyMissing, ?string $search, int $page, int $perPage): array
    {
        $query = $this->keys($tenantId, $locale, $group, $onlyMissing, $search);
        $total = (int) DB::query()->fromSub((clone $query)->select('t.key'), 'k')->count();
        $perPage = max(1, min($perPage, 200));
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $lastPage));

        $keys = $query->select('t.key')->orderBy('t.key')->forPage($page, $perPage)->pluck('key')->map(static fn (mixed $key): string => (string) $key)->all();

        return [
            'rows' => $this->rows($tenantId, $locale, $keys),
            'total' => $total,
            'current_page' => $page,
            'per_page' => $perPage,
            'last_page' => $lastPage,
            'missing_count' => $this->missingCount($tenantId, $locale, $group),
        ];
    }

    public function missingCount(string $tenantId, Locale $locale, ?string $group): int
    {
        return (int) DB::query()->fromSub($this->keys($tenantId, $locale, $group, true, null)->select('t.key'), 'k')->count();
    }

    /**
     * The groups (first key segment) of the dictionary, cached with the dictionary version.
     *
     * @return list<string>
     */
    public function groups(string $tenantId): array
    {
        /** @var list<string> $groups */
        $groups = Cache::remember('i18n:groups:'.$tenantId.':'.$this->cache->version($tenantId), 3600, function () use ($tenantId): array {
            $keys = DB::table('translations')->whereNull('deleted_at')->where(static function (Builder $query) use ($tenantId): void {
                $query->whereNull('tenant_id')->orWhere('tenant_id', $tenantId);
            })->distinct()->pluck('key');

            $groups = [];

            foreach ($keys as $key) {
                $groups[explode('.', (string) $key, 2)[0]] = true;
            }

            $names = array_map('strval', array_keys($groups));
            sort($names);

            return $names;
        });

        return $groups;
    }

    /**
     * One row per key with the effective value of every requested locale (the exact and language texts of the tenant, then
     * those of the system; never the English fallback, so a gap stays visible in the sheet).
     *
     * @param  list<Locale>  $locales
     * @return list<array<string, string>> rows with a "key" entry and one entry per locale code
     */
    public function matrix(string $tenantId, array $locales): array
    {
        $values = [];

        foreach ($locales as $locale) {
            $merged = [];
            $layers = [[null, $locale->base()->value()], [null, $locale->value()], [$tenantId, $locale->base()->value()], [$tenantId, $locale->value()]];

            foreach ($layers as [$scope, $code]) {
                $merged = array_replace($merged, $this->repository->dictionary($scope, $code));
            }

            $values[$locale->value()] = $merged;
        }

        // Every key of the dictionary is listed, translated or not, so the sheet can be filled in and sent back
        $keys = array_fill_keys(array_keys($this->repository->dictionary(null, 'en')), true);

        foreach ($values as $map) {
            foreach (array_keys($map) as $key) {
                $keys[(string) $key] = true;
            }
        }

        $names = array_map('strval', array_keys($keys));
        sort($names);

        $rows = [];

        foreach ($names as $key) {
            $row = ['key' => $key];

            foreach ($locales as $locale) {
                $row[$locale->value()] = $values[$locale->value()][$key] ?? '';
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * The distinct keys of the dictionary (system and tenant) that pass the filters.
     */
    private function keys(string $tenantId, Locale $locale, ?string $group, bool $onlyMissing, ?string $search): Builder
    {
        $query = DB::table('translations as t')->whereNull('t.deleted_at')->where(static function (Builder $inner) use ($tenantId): void {
            $inner->whereNull('t.tenant_id')->orWhere('t.tenant_id', $tenantId);
        })->groupBy('t.key');

        if ($group !== null && $group !== '') {
            $query->whereRaw("t.key LIKE ? ESCAPE '\\'", [$this->escapeLike($group).'.%']);
        }

        if ($search !== null && trim($search) !== '') {
            $pattern = '%'.$this->escapeLike(mb_strtolower(trim($search))).'%';
            $query->where(function (Builder $inner) use ($pattern, $tenantId): void {
                $inner->whereRaw("LOWER(t.key) LIKE ? ESCAPE '\\'", [$pattern])->orWhereIn('t.key', function (Builder $values) use ($pattern, $tenantId): void {
                    $values->select('s.key')->from('translations as s')->whereNull('s.deleted_at')
                        ->where(static function (Builder $scope) use ($tenantId): void {
                            $scope->whereNull('s.tenant_id')->orWhere('s.tenant_id', $tenantId);
                        })
                        ->whereRaw("LOWER(s.value) LIKE ? ESCAPE '\\'", [$pattern]);
                });
            });
        }

        if ($onlyMissing) {
            $codes = array_values(array_unique([$locale->value(), $locale->base()->value()]));
            $query->whereNotExists(static function (Builder $exists) use ($codes, $tenantId): void {
                $exists->selectRaw('1')->from('translations as m')->whereColumn('m.key', 't.key')->whereIn('m.locale', $codes)->whereNull('m.deleted_at')
                    ->where('m.value', '<>', '')
                    ->where(static function (Builder $scope) use ($tenantId): void {
                        $scope->whereNull('m.tenant_id')->orWhere('m.tenant_id', $tenantId);
                    });
            });
        }

        return $query;
    }

    /**
     * @param  list<string>  $keys
     * @return list<array<string, mixed>>
     */
    private function rows(string $tenantId, Locale $locale, array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $codes = array_values(array_unique([$locale->value(), $locale->base()->value(), 'en']));
        $stored = DB::table('translations')->whereNull('deleted_at')->whereIn('key', $keys)->whereIn('locale', $codes)
            ->where(static function (Builder $query) use ($tenantId): void {
                $query->whereNull('tenant_id')->orWhere('tenant_id', $tenantId);
            })->get(['tenant_id', 'locale', 'key', 'value', 'is_locked', 'is_deprecated']);

        $byKey = [];
        $locked = [];
        $deprecated = [];

        foreach ($stored as $row) {
            $key = (string) $row->key;
            $byKey[$key][($row->tenant_id === null ? '' : $tenantId).'|'.$row->locale] = (string) $row->value;
            $locked[$key] = ($locked[$key] ?? false) || ((bool) $row->is_locked && $row->tenant_id === null);
            $deprecated[$key] = ($deprecated[$key] ?? false) || (bool) $row->is_deprecated;
        }

        $rows = [];

        foreach ($keys as $key) {
            $texts = $byKey[$key] ?? [];
            $effective = null;
            $system = null;
            $english = null;

            foreach ($this->resolver->candidates($locale, $tenantId) as [$scope, $candidate]) {
                $text = $texts[($scope === null ? '' : $tenantId).'|'.$candidate->value()] ?? null;

                if ($text === null || $text === '') {
                    continue;
                }

                if ($candidate->value() === 'en' && $scope === null && $locale->base()->value() !== 'en') {
                    $english = $text;

                    continue;
                }

                $effective ??= $text;

                if ($scope === null) {
                    $system ??= $text;
                }
            }

            $rows[] = [
                'key' => $key,
                'group' => explode('.', $key, 2)[0],
                'locale' => $locale->value(),
                'value' => $effective ?? $english ?? $key,
                'system_value' => $system ?? $english,
                'is_overridden' => isset($texts[$tenantId.'|'.$locale->value()]),
                'is_locked' => $locked[$key] ?? false,
                'is_deprecated' => $deprecated[$key] ?? false,
                'is_missing' => $effective === null,
            ];
        }

        return $rows;
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
