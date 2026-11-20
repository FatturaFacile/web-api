<?php

declare(strict_types=1);

namespace Modules\Copilot\Tools;

use Modules\Copilot\Contracts\ReadQueryTool;
use Modules\Copilot\DTOs\ToolContext;
use Modules\Copilot\DTOs\ToolResult;
use Modules\Copilot\Enums\ToolType;
use Modules\Copilot\Services\KnowledgeRetriever;

/**
 * Searches the regulatory and tenant knowledge base (Swiss and Italian law, FatturaPA codes, documents of the company).
 */
final readonly class KnowledgeSearchTool implements ReadQueryTool
{
    public function __construct(private KnowledgeRetriever $retriever) {}

    public function name(): string
    {
        return 'search_knowledge';
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
        return 'Search the regulatory knowledge base (Swiss and Italian accounting, VAT and e-invoicing rules) and the knowledge documents of the company. Cite what you use.';
    }

    public function schema(ToolContext $context): array
    {
        return [
            'type' => 'object',
            'properties' => ['query' => ['type' => 'string', 'description' => 'What to look up']],
            'required' => ['query'],
        ];
    }

    public function rules(ToolContext $context): array
    {
        return ['query' => ['required', 'string', 'max:500']];
    }

    public function run(ToolContext $context, array $arguments): ToolResult
    {
        $hits = $this->retriever->retrieve($context->tenantId, (string) $arguments['query'], $context->locale);

        return new ToolResult(
            ['passages' => array_map(static fn (array $hit): array => [
                'ref' => trim($hit['chunk']->document_title.' '.$hit['chunk']->section_ref),
                'text' => $hit['chunk']->content_text,
            ], $hits)],
            null,
            array_map(fn (array $hit): array => $this->retriever->citation($hit['chunk']), $hits),
        );
    }
}
