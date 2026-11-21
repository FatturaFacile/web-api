<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Application\Handlers;

use Modules\AiCognitive\Application\Commands\ApproveAiSuggestionCommand;
use Modules\AiCognitive\Infrastructure\Models\AiSuggestion;
use Modules\AiCognitive\Infrastructure\Services\AiTenantRunner;
use Modules\AiCognitive\Infrastructure\Services\SuggestionService;

final readonly class ApproveAiSuggestionHandler
{
    public function __construct(private AiTenantRunner $runner, private SuggestionService $suggestions) {}

    public function handle(ApproveAiSuggestionCommand $command): AiSuggestion
    {
        return $this->runner->run($command->tenantId, fn (): AiSuggestion => $this->suggestions->approve($command->tenantId, $command->suggestionId, $command->userId, $command->editedPayload, $command->feedback));
    }
}
