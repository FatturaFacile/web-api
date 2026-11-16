<?php

declare(strict_types=1);

namespace Modules\Collaboration\Services;

use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * A kind of record that collaboration features attach to: searchable in the global search, commentable and taggable.
 * The model must keep its rows inside the active company (BelongsToTenant) and the module is the key of
 * config/permissions.php that decides who may read it.
 */
final readonly class RecordType
{
    /**
     * @param  string  $key  stable identifier used in URLs and polymorphic columns (at most 40 characters)
     * @param  class-string<Model>  $modelClass
     * @param  string  $module  module of config/permissions.php that grants read access
     * @param  string  $labelKey  translation key of the type name
     * @param  list<string>  $searchColumns  text columns matched by the global search
     * @param  Closure(Model): string  $title
     * @param  (Closure(Model): ?string)|null  $subtitle
     * @param  string  $path  frontend route of the record, with an optional {id} placeholder
     */
    public function __construct(
        public string $key,
        public string $modelClass,
        public string $module,
        public string $labelKey,
        public array $searchColumns,
        public Closure $title,
        public ?Closure $subtitle = null,
        public string $path = '/',
    ) {}

    public function titleOf(Model $record): string
    {
        return trim(($this->title)($record));
    }

    public function subtitleOf(Model $record): ?string
    {
        if ($this->subtitle === null) {
            return null;
        }

        $subtitle = ($this->subtitle)($record);

        return $subtitle === null || trim($subtitle) === '' ? null : trim($subtitle);
    }

    public function pathOf(Model $record): string
    {
        return str_replace('{id}', (string) $record->getKey(), $this->path);
    }
}
