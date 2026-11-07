<?php

declare(strict_types=1);

namespace Modules\Assistant\Catalogue;

use Modules\Assistant\Contracts\CatalogueQuery;

/**
 * The closed list of what the assistant can run. A new question means a new reviewed class here, nothing else.
 */
final class QueryCatalogue
{
    /**
     * @var list<class-string<CatalogueQuery>>
     */
    private const array Queries = [
        OpenReceivablesQuery::class,
        OverdueReceivablesQuery::class,
        TopCustomersQuery::class,
        RevenueByMonthQuery::class,
        OpenPayablesQuery::class,
        InvoicesAwaitingApprovalQuery::class,
        CashPositionQuery::class,
        ExpiringContractsQuery::class,
    ];

    /**
     * @return list<CatalogueQuery>
     */
    public function all(): array
    {
        return array_map(static fn (string $class): CatalogueQuery => app($class), self::Queries);
    }

    public function find(string $key): ?CatalogueQuery
    {
        foreach ($this->all() as $query) {
            if ($query->key() === $key) {
                return $query;
            }
        }

        return null;
    }

    /**
     * Only declared parameters survive, only as integers within their bounds. Anything else falls back to the default.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, int>
     */
    public function validatedParameters(CatalogueQuery $query, array $raw): array
    {
        $parameters = [];

        foreach ($query->parameters() as $name => $rule) {
            $value = $raw[$name] ?? null;
            $isValid = (is_int($value) || (is_string($value) && ctype_digit($value))) && (int) $value >= $rule['min'] && (int) $value <= $rule['max'];
            $parameters[$name] = $isValid ? (int) $value : $rule['default'];
        }

        return $parameters;
    }
}
