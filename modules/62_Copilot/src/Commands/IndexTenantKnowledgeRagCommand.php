<?php

declare(strict_types=1);

namespace Modules\Copilot\Commands;

use Modules\Copilot\Services\KnowledgeIndexer;

/**
 * Indexes documents of one tenant into its private knowledge base (the global regulatory chunks come from the seeder).
 */
final readonly class IndexTenantKnowledgeRagCommand
{
    public function __construct(private KnowledgeIndexer $indexer) {}

    /**
     * @param  list<array{title: string, content: string, jurisdiction: string, locale: string, section_ref?: string|null}>  $documents
     * @return int number of chunks written
     */
    public function execute(string $tenantId, array $documents): int
    {
        return $this->indexer->index($tenantId, array_map(static fn (array $document): array => $document + ['source_type' => 'tenant_document'], $documents));
    }
}
