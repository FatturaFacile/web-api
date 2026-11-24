<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * One saved version of a knowledge base article.
 */
final class KnowledgeArticleVersion extends BaseEnterpriseModel
{
    protected $table = 'esm_knowledge_article_versions';

    protected function casts(): array
    {
        return [
            'version' => 'integer',
        ];
    }
}
