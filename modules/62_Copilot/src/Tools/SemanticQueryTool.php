<?php

declare(strict_types=1);

namespace Modules\Copilot\Tools;

use Enterprise\MultiTenant\Services\ModuleAccessGate;
use Illuminate\Auth\Access\AuthorizationException;
use Modules\Copilot\Contracts\ReadQueryTool;
use Modules\Copilot\DTOs\ToolContext;
use Modules\Copilot\DTOs\ToolResult;
use Modules\Copilot\Enums\ToolType;
use Modules\Copilot\Semantic\ExecuteGovernedSemanticQuery;
use Modules\Copilot\Semantic\SemanticEntityRegistry;
use Modules\Copilot\Semantic\SemanticQueryException;

/**
 * Text-to-Query without text-to-SQL: the model fills the typed Semantic Query structure and the engine runs it.
 */
final readonly class SemanticQueryTool implements ReadQueryTool
{
    public function __construct(
        private ExecuteGovernedSemanticQuery $engine,
        private SemanticEntityRegistry $entities,
        private ModuleAccessGate $gate,
    ) {}

    public function name(): string
    {
        return 'semantic_query';
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
        return 'Query business data with a structured query: an entity, optional metrics (count, sum, avg, min, max), dimensions (a field, or month:<date field> / year:<date field>), filters, ordering and a chart hint. Without metrics and dimensions it lists records (optionally with `select`). Fields the user may not see are refused or redacted.';
    }

    public function schema(ToolContext $context): array
    {
        $entities = [];
        $descriptions = [];

        foreach ($this->entities->all() as $key => $entity) {
            if ($this->gate->allows($context->user, $context->tenantId, $entity->module, 'read')) {
                $entities[] = $key;
                $descriptions[] = $key.' ('.$entity->description.'): '.implode(', ', array_keys($entity->fields));
            }
        }

        return [
            'type' => 'object',
            'properties' => [
                'entity' => ['type' => 'string', 'enum' => $entities, 'description' => 'Entities and fields: '.implode('; ', $descriptions)],
                'metrics' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'fn' => ['type' => 'string', 'enum' => ['count', 'sum', 'avg', 'min', 'max']],
                    'field' => ['type' => 'string'],
                    'alias' => ['type' => 'string'],
                ], 'required' => ['fn']]],
                'dimensions' => ['type' => 'array', 'items' => ['type' => 'string']],
                'filters' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'field' => ['type' => 'string'],
                    'op' => ['type' => 'string', 'enum' => ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'in', 'between', 'contains']],
                    'value' => ['description' => 'A value, or a list for in / between'],
                ], 'required' => ['field', 'op', 'value']]],
                'select' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Fields to list when there are no metrics'],
                'order_by' => ['type' => 'object', 'properties' => [
                    'field' => ['type' => 'string'],
                    'direction' => ['type' => 'string', 'enum' => ['asc', 'desc']],
                ]],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200],
                'chart' => ['type' => 'string', 'enum' => ['bar', 'line', 'pie', 'kpi_card', 'table']],
            ],
            'required' => ['entity'],
        ];
    }

    public function rules(ToolContext $context): array
    {
        return [
            'entity' => ['required', 'string', 'max:40'],
            'metrics' => ['nullable', 'array', 'max:5'],
            'metrics.*.fn' => ['required', 'string', 'max:10'],
            'metrics.*.field' => ['nullable', 'string', 'max:60'],
            'metrics.*.alias' => ['nullable', 'string', 'max:41'],
            'dimensions' => ['nullable', 'array', 'max:3'],
            'dimensions.*' => ['string', 'max:80'],
            'filters' => ['nullable', 'array', 'max:10'],
            'filters.*.field' => ['required', 'string', 'max:60'],
            'filters.*.op' => ['required', 'string', 'max:10'],
            'filters.*.value' => ['present'],
            'select' => ['nullable', 'array', 'max:20'],
            'select.*' => ['string', 'max:60'],
            'order_by' => ['nullable', 'array'],
            'order_by.field' => ['required_with:order_by', 'string', 'max:60'],
            'order_by.direction' => ['nullable', 'in:asc,desc'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
            'chart' => ['nullable', 'in:bar,line,pie,kpi_card,table'],
        ];
    }

    public function run(ToolContext $context, array $arguments): ToolResult
    {
        try {
            return $this->engine->execute($context, $arguments);
        } catch (SemanticQueryException $exception) {
            return new ToolResult(['error' => $exception->getMessage()]);
        } catch (AuthorizationException) {
            return new ToolResult(['error' => 'The user is not allowed to read this entity.']);
        }
    }
}
