<?php

declare(strict_types=1);

namespace Modules\Copilot\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;

/**
 * A passage of the knowledge base the Copilot cites: global (tenant_id null) regulatory documents shared by every tenant,
 * or documents of one tenant. The embedding is a list of floats.
 *
 * @property string $source_type
 * @property string $jurisdiction
 * @property string $locale
 * @property string $document_title
 * @property string|null $section_ref
 * @property string $content_text
 * @property array<int, float>|null $embedding_vector
 * @property array<string, mixed>|null $metadata_json
 */
final class CopilotKnowledgeChunk extends BaseEnterpriseModel
{
    use AllowsGlobalRecords;

    protected $table = 'copilot_knowledge_chunks';

    protected function casts(): array
    {
        return [
            'embedding_vector' => 'array',
            'metadata_json' => 'array',
        ];
    }
}
