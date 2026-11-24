<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A knowledge base article linked to a ticket, by an agent or by the resolution.
 */
final class TicketKnowledgeLink extends BaseEnterpriseModel
{
    protected $table = 'esm_ticket_knowledge_links';

    /**
     * @return BelongsTo<KnowledgeArticle, $this>
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(KnowledgeArticle::class, 'article_id');
    }
}
