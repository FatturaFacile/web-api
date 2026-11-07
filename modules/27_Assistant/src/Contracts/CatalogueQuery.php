<?php

declare(strict_types=1);

namespace Modules\Assistant\Contracts;

/**
 * One safe question the assistant can answer: written in code, parameterized and scoped to the active tenant. Nothing
 * a person or a model types ever becomes part of a query, except the validated integer parameters declared here.
 */
interface CatalogueQuery
{
    public function key(): string;

    /**
     * Module of config/permissions.php whose read access the caller needs.
     */
    public function module(): string;

    public function description(): string;

    /**
     * Lowercase phrases that point to this query, in the languages of the application.
     *
     * @return list<string>
     */
    public function keywords(): array;

    /**
     * @return array<string, array{min: int, max: int, default: int}>
     */
    public function parameters(): array;

    /**
     * @param  array<string, int>  $parameters  already validated
     * @return list<array<string, mixed>>
     */
    public function run(string $tenantId, array $parameters): array;
}
