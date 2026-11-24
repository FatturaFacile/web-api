<?php

declare(strict_types=1);

namespace Modules\Copilot\Semantic;

use Illuminate\Database\Eloquent\Model;

/**
 * An entity of the ERP the Semantic Query DSL can read, with the closed list of its queryable fields.
 */
final readonly class SemanticEntity
{
    /**
     * @param  class-string<Model>  $modelClass
     * @param  array<string, SemanticField>  $fields
     * @param  string  $route  client route of the record, `{id}` replaced by the record id
     */
    public function __construct(
        public string $key,
        public string $modelClass,
        public string $module,
        public string $description,
        public string $labelColumn,
        public string $citationType,
        public string $route,
        public array $fields,
    ) {}

    public function table(): string
    {
        return (new ($this->modelClass))->getTable();
    }
}
