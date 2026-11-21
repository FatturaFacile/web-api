<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Infrastructure\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\AiCognitive\Infrastructure\Services\AiAuditor;
use Modules\AiCognitive\Infrastructure\Services\VectorIndexService;

/**
 * Batch embedding of the tenant knowledge index. Unchanged chunks keep their vector, so a second run costs almost nothing.
 */
final class RebuildTenantVectorIndexJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 900;

    public function __construct(public readonly string $tenantId, public readonly ?string $userId = null)
    {
        $this->onQueue('ai');
    }

    public function handle(VectorIndexService $index, AiAuditor $auditor): void
    {
        $stats = $index->rebuild($this->tenantId);

        $auditor->record($this->tenantId, 'index_rebuilt', $this->userId, null, null, null, null, null, $stats);
    }
}
