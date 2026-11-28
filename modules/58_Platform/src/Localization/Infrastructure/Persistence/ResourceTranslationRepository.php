<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Platform\Localization\Domain\Enums\TranslatableResource;
use Modules\Platform\Localization\Domain\Exceptions\ReadOnlyResourceException;

/**
 * Reads and writes the `<resource>_translations` tables of the master data: one row per record and locale.
 */
final class ResourceTranslationRepository
{
    /**
     * The master record when it belongs to the tenant or is global (readable by everybody).
     *
     * @throws ModelNotFoundException when the record does not exist for this tenant
     */
    public function record(TranslatableResource $resource, string $id, string $tenantId): object
    {
        $record = DB::table($resource->table())->where('id', $id)->where(static function (Builder $query) use ($tenantId): void {
            $query->whereNull('tenant_id')->orWhere('tenant_id', $tenantId);
        })->first();

        if ($record === null) {
            throw (new ModelNotFoundException)->setModel($resource->modelClass(), [$id]);
        }

        return $record;
    }

    /**
     * The translated values of a record keyed by locale code.
     *
     * @return array<string, array<string, string|null>>
     */
    public function values(TranslatableResource $resource, string $id): array
    {
        $values = [];

        foreach (DB::table($resource->translationTable())->where($resource->foreignKey(), $id)->get() as $row) {
            $values[(string) $row->locale] = array_combine($resource->fields(), array_map(
                static fn (string $field): ?string => isset($row->{$field}) && $row->{$field} !== '' ? (string) $row->{$field} : null,
                $resource->fields(),
            ));
        }

        return $values;
    }

    /**
     * Stores the values of one locale; a locale whose values are all empty loses its row.
     *
     * @param  array<string, string|null>  $values
     *
     * @throws ReadOnlyResourceException when the record is global
     */
    public function save(TranslatableResource $resource, string $id, string $tenantId, string $locale, array $values): void
    {
        $record = $this->record($resource, $id, $tenantId);

        if ($record->tenant_id === null) {
            throw new ReadOnlyResourceException($resource->value, $id);
        }

        $identity = [$resource->foreignKey() => $id, 'locale' => $locale];
        $columns = [];

        foreach ($resource->fields() as $field) {
            $text = array_key_exists($field, $values) ? trim((string) $values[$field]) : null;
            $columns[$field] = $text === '' ? null : $text;
        }

        if (count(array_filter($columns, static fn (?string $value): bool => $value !== null)) === 0) {
            DB::table($resource->translationTable())->where($identity)->delete();

            return;
        }

        // Payment term translations require a name: the one of the record stands in until a translated one is entered
        if ($resource === TranslatableResource::PaymentTerm && $columns['name'] === null) {
            $columns['name'] = (string) $record->name;
        }

        $now = now();
        $exists = DB::table($resource->translationTable())->where($identity)->exists();

        if ($exists) {
            DB::table($resource->translationTable())->where($identity)->update($columns + ['tenant_id' => $tenantId, 'updated_at' => $now]);
        } else {
            DB::table($resource->translationTable())->insert($identity + $columns + ['tenant_id' => $tenantId, 'created_at' => $now, 'updated_at' => $now]);
        }
    }
}
