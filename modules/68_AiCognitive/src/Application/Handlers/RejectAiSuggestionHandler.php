<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Application\Handlers;

use Modules\AiCognitive\Application\Commands\RejectAiSuggestionCommand;
use Modules\AiCognitive\Infrastructure\Models\AiSuggestion;
use Modules\AiCognitive\Infrastructure\Services\AiTenantRunner;
use Modules\AiCognitive\Infrastructure\Services\SuggestionService;

final readonly class RejectAiSuggestionHandler
{
    public function __construct(private AiTenantRunner $runner, private SuggestionService $suggestions) {}

    public function handle(RejectAiSuggestionCommand $command): AiSuggestion
    {
        return $this->runner->run($command->tenantId, fn (): AiSuggestion => $this->suggestions->reject($command->tenantId, $command->suggestionId, $command->userId, $command->reason));
    }
}
