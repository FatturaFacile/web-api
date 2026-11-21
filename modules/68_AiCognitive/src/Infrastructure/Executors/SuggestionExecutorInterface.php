<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Infrastructure\Executors;

use Modules\AiCognitive\Domain\Enums\SuggestionKind;
use Modules\AiCognitive\Domain\Exceptions\AiCognitiveException;

/**
 * Carries out an approved suggestion: creates the ERP record of a draft, links a bank transaction, or only acknowledges an
 * alert. The payload is the final one (edited by the reviewer, or the proposal as it was).
 */
interface SuggestionExecutorInterface
{
    public function supports(SuggestionKind $kind): bool;

    /**
     * @param  array<string, mixed>  $payload
     * @return array{type: string|null, id: string|null}
     *
     * @throws AiCognitiveException when the payload cannot be turned into a record; the reviewer can edit and retry
     */
    public function execute(string $tenantId, ?string $userId, SuggestionKind $kind, array $payload): array;
}
