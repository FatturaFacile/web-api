<?php

declare(strict_types=1);

namespace Enterprise\EventBus\Jobs;

use Enterprise\EventBus\Models\TransactionalOutbox;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
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

    public function __construct()
    {
        $this->afterCommit();
    }

    public function handle(): void
    {
        // 1. Concurrently safe batch acquisition with SKIP LOCKED inside PostgreSQL transaction
        $pendingEvents = DB::transaction(function () {
            $batch = TransactionalOutbox::where('status', 'pending')
                ->where(function ($query): void {
                    $query->whereNull('retry_after')
                        ->orWhere('retry_after', '<=', now());
                })
                ->orderBy('occurred_at')
                ->limit(50)
                ->lock('FOR UPDATE SKIP LOCKED')
                ->get();

            foreach ($batch as $outbox) {
                $outbox->update(['status' => 'processing']);
            }

            return $batch;
        });

        // 2. Dispatch events and manage outcome
        foreach ($pendingEvents as $outbox) {
            try {
                Event::dispatch($outbox->event_type, [$outbox->payload_json]);

                $outbox->update([
                    'status' => 'completed',
                    'processed_at' => now(),
                    'error_message' => null,
                ]);
            } catch (Throwable $e) {
                $retryCount = $outbox->retry_count + 1;
                $isDeadLetter = $retryCount >= 5;
                $backoffSeconds = (int) (pow(2, $retryCount) * 10);
                $retryAfter = $isDeadLetter ? null : now()->addSeconds($backoffSeconds);

                $outbox->update([
                    'retry_count' => $retryCount,
                    'status' => $isDeadLetter ? 'failed' : 'pending',
                    'retry_after' => $retryAfter,
                    'error_message' => $e->getMessage(),
                ]);

                if ($isDeadLetter) {
                    Log::critical("Transactional outbox event [{$outbox->id}] marked as DEAD LETTER / poison message: {$e->getMessage()}", [
                        'outbox_id' => $outbox->id,
                        'event_type' => $outbox->event_type,
                        'tenant_id' => $outbox->tenant_id,
                        'retry_count' => $retryCount,
                    ]);
                }
            }
        }
    }
}
