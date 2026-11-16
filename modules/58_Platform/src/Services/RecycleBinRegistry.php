<?php

declare(strict_types=1);

namespace Modules\Platform\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The allowlist of record types the recycle bin handles (config `platform.recycle_bin`). A type outside the list can
 * never be listed, restored or destroyed through the API, and only models with SoftDeletes are accepted.
 */
final class RecycleBinRegistry
{
    /**
     * @return list<string>
     */
    public function types(): array
    {
        return array_keys($this->definitions());
    }

    public function has(string $type): bool
    {
        return isset($this->definitions()[$type]);
    }

    public function isPurgeable(string $type): bool
    {
        return (bool) ($this->definitions()[$type]['purgeable'] ?? false);
    }

    /**
     * @return Builder<Model>
     */
    public function trashed(string $type): Builder
    {
        /** @var class-string<Model> $class */
        $class = $this->definitions()[$type]['model'];

        /** @var Builder<Model> $query */
        $query = $class::query()->onlyTrashed();

        return $query;
    }

    public function find(string $type, string $id): ?Model
    {
        return $this->trashed($type)->whereKey($id)->first();
    }

    /**
     * A human readable name of a record: the first filled column of the type's label list, else its id.
     */
    public function label(string $type, Model $record): string
    {
        foreach ($this->definitions()[$type]['label'] ?? [] as $column) {
            $value = $record->getAttribute($column);

            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return (string) $record->getKey();
    }

    /**
     * @return array<string, array{model: class-string<Model>, label: list<string>, purgeable: bool}>
     */
    private function definitions(): array
    {
        /** @var array<string, array{model: class-string<Model>, label: list<string>, purgeable: bool}> $configured */
        $configured = config('platform.recycle_bin', []);

        return array_filter($configured, static fn (array $definition): bool => in_array(SoftDeletes::class, class_uses_recursive($definition['model']), true));
    }
}
