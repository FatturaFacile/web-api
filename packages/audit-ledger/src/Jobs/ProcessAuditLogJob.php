<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Jobs;

use Enterprise\AuditLedger\Actions\RecordAuditAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Appends an entry later: the asynchronous mode of the ledger and the retry queue of read-only events (views, downloads)
 * that could not be written when they happened.
 */
final class ProcessAuditLogJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 8;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public array $data
    ) {
        $this->afterCommit();
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60, 300, 900, 1800, 3600];
    }

    public function handle(RecordAuditAction $action): void
    {
        $action->executeFromPayload($this->data);
    }
}
