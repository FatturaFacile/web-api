<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Infrastructure\Executors;

use Modules\AiCognitive\Domain\Enums\SuggestionKind;

/**
 * Accounting codings, anomaly flags and predictive recommendations create no record by themselves: approving them records
 * the human decision (and feeds the feedback loop), the follow-up action is a deliberate click in the owning module.
 */
final class AcknowledgeExecutor implements SuggestionExecutorInterface
{
    public function supports(SuggestionKind $kind): bool
    {
        return ! $kind->isDraftEntity() && $kind !== SuggestionKind::ReconciliationMatch;
    }

    public function execute(string $tenantId, ?string $userId, SuggestionKind $kind, array $payload): array
    {
        return ['type' => null, 'id' => null];
    }
}
