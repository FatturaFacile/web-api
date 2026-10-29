<?php

declare(strict_types=1);

namespace Enterprise\EventBus\Jobs;

use Enterprise\EventBus\Services\OutboxLifecycleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class MaintainOutboxLifecycleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $archiveDays = 30,
        public int $retentionDays = 90,
        public int $batchSize = 500
    ) {}

    public function handle(OutboxLifecycleService $lifecycleService): void
    {
        $lifecycleService->archiveProcessedMessages($this->archiveDays, $this->batchSize);
        $lifecycleService->purgeArchivedMessages($this->retentionDays, $this->batchSize);
    }
}
