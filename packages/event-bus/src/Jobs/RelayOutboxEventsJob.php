<?php

declare(strict_types=1);

namespace Enterprise\EventBus\Jobs;

use Carbon\CarbonInterface;
use Enterprise\EventBus\Models\TransactionalOutbox;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Throwable;

final class RelayOutboxEventsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const int MAX_RETRIES = 5;

    public int $timeout = 300;

    public int $tries = 1;

    /**
     * @param  int  $staleProcessingMinutes  an event still processing after this long belongs to a worker that died
     */
    public function __construct(public int $staleProcessingMinutes = 10)
    {
        $this->afterCommit();
    }

    public function handle(): void
    {
        // 1. Concurrently safe batch acquisition with SKIP LOCKED inside PostgreSQL transaction. While an event is
        // processing, retry_after holds the end of its lease: once the lease expires another run takes it over.
        $pendingEvents = DB::transaction(function () {
            $now = now();

            $batch = TransactionalOutbox::query()
                ->where(function (Builder $query) use ($now): void {
                    $query->where(function (Builder $pending) use ($now): void {
                        $pending->where('status', TransactionalOutbox::STATUS_PENDING)
                            ->where(fn (Builder $due) => $due->whereNull('retry_after')->orWhere('retry_after', '<=', $now));
                    })->orWhere(fn (Builder $stale) => $this->staleProcessing($stale, $now));
                })
                ->orderBy('occurred_at')
                ->limit(50)
                ->lock('FOR UPDATE SKIP LOCKED')
                ->get();

            $claimed = [];

            foreach ($batch as $outbox) {
                if ($outbox->status === TransactionalOutbox::STATUS_PROCESSING) {
                    // The previous run died while dispatching it: that counts as a failed attempt.
                    $retryCount = $outbox->retry_count + 1;

                    if ($retryCount >= self::MAX_RETRIES) {
                        $this->markDeadLetter($outbox, $retryCount, 'Relay interrupted: the worker stopped while dispatching the event.');

                        continue;
                    }

                    $outbox->retry_count = $retryCount;
                }

                $outbox->status = TransactionalOutbox::STATUS_PROCESSING;
                $outbox->retry_after = $now->copy()->addMinutes($this->staleProcessingMinutes);
                $outbox->save();
                $claimed[] = $outbox;
            }

            return $claimed;
        });

        // 2. Dispatch events and manage outcome
        foreach ($pendingEvents as $outbox) {
            try {
                Event::dispatch($outbox->event_type, [$outbox->payload_json]);

                $outbox->update([
                    'status' => TransactionalOutbox::STATUS_COMPLETED,
                    'processed_at' => now(),
                    'retry_after' => null,
                    'error_message' => null,
                ]);
            } catch (Throwable $e) {
                $retryCount = $outbox->retry_count + 1;

                if ($retryCount >= self::MAX_RETRIES) {
                    $this->markDeadLetter($outbox, $retryCount, $e->getMessage());

                    continue;
                }

                $backoffSeconds = (int) (pow(2, $retryCount) * 10);

                $outbox->update([
                    'retry_count' => $retryCount,
                    'status' => TransactionalOutbox::STATUS_PENDING,
                    'retry_after' => now()->addSeconds($backoffSeconds),
                    'error_message' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Events stuck in processing: their lease expired, or they were claimed before leases existed and are old enough.
     *
     * @param  Builder<TransactionalOutbox>  $query
     */
    private function staleProcessing(Builder $query, CarbonInterface $now): void
    {
        $query->where('status', TransactionalOutbox::STATUS_PROCESSING)
            ->where(function (Builder $expired) use ($now): void {
                $expired->where('retry_after', '<=', $now)
                    ->orWhere(fn (Builder $legacy) => $legacy->whereNull('retry_after')
                        ->where('occurred_at', '<=', $now->copy()->subMinutes($this->staleProcessingMinutes)));
            });
    }

    private function markDeadLetter(TransactionalOutbox $outbox, int $retryCount, string $reason): void
    {
        $outbox->update([
            'retry_count' => $retryCount,
            'status' => TransactionalOutbox::STATUS_FAILED,
            'retry_after' => null,
            'error_message' => $reason,
        ]);

        Log::critical("Transactional outbox event [{$outbox->id}] marked as DEAD LETTER / poison message: {$reason}", [
            'outbox_id' => $outbox->id,
            'event_type' => $outbox->event_type,
            'tenant_id' => $outbox->tenant_id,
            'retry_count' => $retryCount,
        ]);
    }
}
