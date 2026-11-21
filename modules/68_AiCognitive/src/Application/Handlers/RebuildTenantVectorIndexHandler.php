<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Application\Handlers;

use Modules\AiCognitive\Application\Commands\RebuildTenantVectorIndexCommand;
use Modules\AiCognitive\Infrastructure\Jobs\RebuildTenantVectorIndexJob;

/**
 * Queues the batch embedding job (or runs it inline for a console call that asked to wait).
 */
final readonly class RebuildTenantVectorIndexHandler
{
    /**
     * @return array{queued: bool}
     */
    public function handle(RebuildTenantVectorIndexCommand $command): array
    {
        $command->runNow
            ? RebuildTenantVectorIndexJob::dispatchSync($command->tenantId, $command->userId)
            : RebuildTenantVectorIndexJob::dispatch($command->tenantId, $command->userId);

        return ['queued' => ! $command->runNow];
    }
}
