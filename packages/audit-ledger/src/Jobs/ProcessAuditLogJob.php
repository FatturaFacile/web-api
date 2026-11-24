<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Jobs;

use Enterprise\AuditLedger\Actions\RecordAuditAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ProcessAuditLogJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public array $data
    ) {
        $this->afterCommit();
    }

    public function handle(RecordAuditAction $action): void
    {
        $action->executeFromPayload($this->data);
    }
}
