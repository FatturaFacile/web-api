<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Application\Handlers;

use Modules\AiCognitive\Application\Commands\ExecuteCognitivePipelineCommand;
use Modules\AiCognitive\Infrastructure\Models\AiSuggestion;
use Modules\AiCognitive\Infrastructure\Services\AiTenantRunner;
use Modules\AiCognitive\Infrastructure\Services\CognitivePipelineService;

final readonly class ExecuteCognitivePipelineHandler
{
    public function __construct(private AiTenantRunner $runner, private CognitivePipelineService $pipeline) {}

    public function handle(ExecuteCognitivePipelineCommand $command): AiSuggestion
    {
        return $this->runner->run($command->tenantId, fn (): AiSuggestion => $this->pipeline->run(
            $command->tenantId,
            $command->userId,
            $command->kind,
            $command->sourceType,
            $command->text,
            $command->sourceReference,
            $command->locale,
        ));
    }
}
