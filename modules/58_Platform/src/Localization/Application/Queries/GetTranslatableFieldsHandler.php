<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Application\Queries;

use Modules\Platform\Localization\Domain\Enums\SupportedLocale;
use Modules\Platform\Localization\Infrastructure\Persistence\ResourceTranslationRepository;
use Modules\Platform\Localization\Infrastructure\Persistence\TenantLocaleSettingsRepository;

/**
 * The translations of a master data record across the active locales of the tenant, with the completeness of each
 * language and of the record.
 */
final class GetTranslatableFieldsHandler
{
    public function __construct(private readonly ResourceTranslationRepository $repository, private readonly TenantLocaleSettingsRepository $settings) {}

    /**
     * @return array{resource: string, id: string, fields: list<string>, active_locales: list<string>, locales: list<array{locale: string, values: array<string, string|null>, filled: int, total: int, percent: int}>, completeness: array{filled: int, total: int, percent: int}}
     */
    public function __invoke(GetTranslatableFieldsQuery $query): array
    {
        $this->repository->record($query->resource, $query->id, $query->tenantId);

        $fields = $query->resource->fields();
        $stored = $this->repository->values($query->resource, $query->id);
        $active = array_map(static fn (SupportedLocale $locale): string => $locale->value, $this->settings->get($query->tenantId)->activeLocales);
        $locales = [];
        $filledTotal = 0;

        foreach ($active as $code) {
            $values = $stored[$code] ?? array_fill_keys($fields, null);
            $filled = count(array_filter($values, static fn (?string $value): bool => $value !== null));
            $filledTotal += $filled;
            $locales[] = ['locale' => $code, 'values' => $values, 'filled' => $filled, 'total' => count($fields), 'percent' => (int) round(100 * $filled / max(1, count($fields)))];
        }

        $total = count($fields) * max(1, count($active));

        return [
            'resource' => $query->resource->value,
            'id' => $query->id,
            'fields' => $fields,
            'active_locales' => $active,
            'locales' => $locales,
            'completeness' => ['filled' => $filledTotal, 'total' => $total, 'percent' => (int) round(100 * $filledTotal / $total)],
        ];
    }
}
