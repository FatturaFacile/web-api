<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Application\Queries;

use App\Models\User;

/**
 * A question to the ERP copilot. Mode `analytics` answers with a governed structured query, `knowledge` with the passages of
 * the tenant index, `auto` tries analytics first and falls back to knowledge.
 */
final readonly class QueryErpCopilotQuery
{
    public function __construct(
        public User $user,
        public string $tenantId,
        public string $question,
        public string $mode = 'auto',
        public string $locale = 'en',
    ) {}
}
