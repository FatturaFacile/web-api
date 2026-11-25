<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Jobs;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\DebtCollection\Models\Batch;
use Modules\DebtCollection\Models\Notice;
use Modules\DebtCollection\Services\BatchService;
use Modules\DebtCollection\Services\DispatchService;

/**
 * Sends one notice of a batch. Idempotent: a notice that is no longer queued (already sent, cancelled) is skipped, so a
 * redelivered job never sends twice. A delivery failure is recorded on the notice, not thrown, so the batch carries on.
 */
final class DispatchNoticeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly string $noticeId,
        public readonly string $batchId,
        public readonly string $tenantId,
        public readonly ?string $actorId,
    ) {}

    public function handle(DispatchService $dispatch, BatchService $batches, TenantContext $context): void
    {
        $context->setTenant(Tenant::query()->findOrFail($this->tenantId));

        $notice = Notice::query()->find($this->noticeId);
        $batch = Batch::query()->find($this->batchId);

        if ($notice !== null && $notice->status === 'queued') {
            $dispatch->send($notice, null, $this->actorId);
        }

        if ($batch !== null) {
            $batches->settle($batch);
        }
    }
}
