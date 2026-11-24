<?php

declare(strict_types=1);

namespace Modules\Copilot\Tools;

use Enterprise\MultiTenant\Services\ModuleAccessGate;
use Illuminate\Validation\Rule;
use Modules\Assistant\Catalogue\QueryCatalogue;
use Modules\Assistant\Contracts\CatalogueQuery;
use Modules\Assistant\Services\ReadOnlyQueryRunner;
use Modules\Copilot\Contracts\ReadQueryTool;
use Modules\Copilot\DTOs\ToolContext;
use Modules\Copilot\DTOs\ToolResult;
use Modules\Copilot\Enums\ToolType;

/**
 * Exposes the reviewed catalogue of the business data assistant (open receivables, cash position, expiring contracts...) as
 * one read tool, each query still gated by the module it reads and run inside a read-only transaction.
 */
final readonly class CatalogueQueryTool implements ReadQueryTool
{
    public function __construct(
        private QueryCatalogue $catalogue,
        private ReadOnlyQueryRunner $runner,
        private ModuleAccessGate $gate,
    ) {}

    public function name(): string
    {
        return 'catalogue_query';
    }

    public function type(): ToolType
    {
        return ToolType::ReadQuery;
    }

    public function module(): string
    {
        return 'copilot';
    }

    public function access(): string
    {
        return 'read';
    }

    public function description(): string
    {
        return 'Run one of the ready-made business questions of the catalogue.';
    }

    public function schema(ToolContext $context): array
    {
        $queries = $this->allowed($context);

        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'enum' => array_map(static fn (CatalogueQuery $query): string => $query->key(), $queries),
                    'description' => implode('; ', array_map(static fn (CatalogueQuery $query): string => $query->key().': '.$query->description(), $queries)),
                ],
                'parameters' => ['type' => 'object', 'description' => 'Optional integer parameters of the query'],
            ],
            'required' => ['query'],
        ];
    }

    public function rules(ToolContext $context): array
    {
        return [
            'query' => ['required', 'string', Rule::in(array_map(static fn (CatalogueQuery $query): string => $query->key(), $this->allowed($context)))],
            'parameters' => ['nullable', 'array'],
        ];
    }

    public function run(ToolContext $context, array $arguments): ToolResult
    {
        $query = $this->catalogue->find((string) $arguments['query']);

        if ($query === null) {
            return new ToolResult(['error' => 'Unknown query.']);
        }

        $parameters = $this->catalogue->validatedParameters($query, (array) ($arguments['parameters'] ?? []));
        $rows = $this->runner->run(static fn (): array => $query->run($context->tenantId, $parameters));

        return new ToolResult(
            ['query' => $query->key(), 'rows' => $rows, 'row_count' => count($rows)],
            $rows === [] ? null : ['type' => 'table', 'title' => $query->description(), 'columns' => array_keys($rows[0]), 'rows' => $rows],
        );
    }

    /**
     * @return list<CatalogueQuery>
     */
    private function allowed(ToolContext $context): array
    {
        return array_values(array_filter(
            $this->catalogue->all(),
            fn (CatalogueQuery $query): bool => $this->gate->allows($context->user, $context->tenantId, $query->module(), 'read'),
        ));
    }
}
