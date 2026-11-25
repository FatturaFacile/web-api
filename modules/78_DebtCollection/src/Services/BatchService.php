<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Services;

use Illuminate\Support\Facades\DB;
use Modules\DebtCollection\Exceptions\DebtCollectionException;
use Modules\DebtCollection\Jobs\DispatchNoticeJob;
use Modules\DebtCollection\Models\Batch;
use Modules\DebtCollection\Models\Notice;

/**
 * Bulk dispatch: a batch groups the notices queued together, one idempotent job per notice sends it, and the batch
 * counters tell how many went out and how many failed so the failures can be retried.
 */
final class BatchService
{
    /**
     * @param  list<string>  $noticeIds
     */
    public function create(string $tenantId, array $noticeIds, string $channel, ?string $actorId): Batch
    {
        if (! in_array($channel, DispatchService::Channels, true)) {
            throw new DebtCollectionException("Unknown channel [{$channel}].", 'invalid_channel');
        }

        $notices = Notice::query()->whereIn('id', $noticeIds)->get();

        if ($notices->isEmpty() || $notices->count() !== count(array_unique($noticeIds))) {
            throw new DebtCollectionException('Some notices were not found.', 'notice_not_found');
        }

        if ($notices->contains(static fn (Notice $notice): bool => ! in_array($notice->status, ['draft', 'failed'], true))) {
            throw new DebtCollectionException('Only draft or failed notices can be dispatched.', 'notice_not_sendable');
        }

        $batch = DB::transaction(function () use ($notices, $channel, $actorId): Batch {
            $batch = Batch::create(['channel' => $channel, 'status' => 'pending', 'total' => $notices->count(), 'sent' => 0, 'failed' => 0, 'created_by' => $actorId]);
            Notice::query()->whereIn('id', $notices->pluck('id')->all())->update(['batch_id' => $batch->id, 'channel' => $channel, 'status' => 'queued', 'error' => null]);

            return $batch;
        });

        $this->enqueue($batch, $tenantId, $notices->pluck('id')->all(), $actorId);

        return $batch->refresh();
    }

    /**
     * Queues the failed notices of a batch again.
     */
    public function retry(Batch $batch, string $tenantId, ?string $actorId): Batch
    {
        $failed = Notice::query()->where('batch_id', $batch->id)->where('status', 'failed')->pluck('id')->all();

        if ($failed === []) {
            throw new DebtCollectionException('The batch has no failed notices.', 'batch_not_retryable');
        }

        Notice::query()->whereIn('id', $failed)->update(['status' => 'queued', 'error' => null]);
        $batch->update(['status' => 'processing', 'failed' => 0, 'completed_at' => null]);
        $this->enqueue($batch, $tenantId, $failed, $actorId);

        return $batch->refresh();
    }

    /**
     * Records the outcome of one notice and closes the batch when every notice has been tried.
     */
    public function settle(Batch $batch): void
    {
        DB::transaction(function () use ($batch): void {
            $locked = Batch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
            $counts = Notice::query()->where('batch_id', $locked->id)->selectRaw("sum(case when status in ('sent','delivered') then 1 else 0 end) as sent, sum(case when status = 'failed' then 1 else 0 end) as failed, sum(case when status = 'queued' then 1 else 0 end) as queued")->first();
            $sent = (int) ($counts->sent ?? 0);
            $failed = (int) ($counts->failed ?? 0);
            $queued = (int) ($counts->queued ?? 0);

            $locked->fill(['sent' => $sent, 'failed' => $failed]);

            if ($queued === 0) {
                $locked->status = $failed === 0 ? 'completed' : ($sent === 0 ? 'failed' : 'partial');
                $locked->completed_at = now();
            } else {
                $locked->status = 'processing';
            }

            $locked->save();
        });
    }

    /**
     * @param  list<string>  $noticeIds
     */
    private function enqueue(Batch $batch, string $tenantId, array $noticeIds, ?string $actorId): void
    {
        $batch->update(['status' => 'processing', 'started_at' => $batch->started_at ?? now()]);

        foreach ($noticeIds as $noticeId) {
            DispatchNoticeJob::dispatch($noticeId, $batch->id, $tenantId, $actorId);
        }
    }
}
