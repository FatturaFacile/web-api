<?php

declare(strict_types=1);

namespace Modules\Assistant\Contracts;

use Modules\Assistant\DTOs\ResolvedIntent;

/**
 * Contract for intent resolver in the Assistant module.
 */
interface IntentResolver
{
    /**
     * Driver or resolver name.
     */
    public function name(): string;

    /**
     * Chooses a catalogue query for a question. The result names a query: it can never carry SQL.
     *
     * @param  list<CatalogueQuery>  $queries  the queries this caller may run
     */
    public function resolve(string $tenantId, string $question, array $queries): ?ResolvedIntent;
}
