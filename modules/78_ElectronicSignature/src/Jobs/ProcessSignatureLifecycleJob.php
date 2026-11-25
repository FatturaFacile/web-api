<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\ElectronicSignature\Services\EnvelopeService;

/**
 * Queued job of a company: expires the overdue signature envelopes, sends the reminders (3 and 1 day before the expiry by
 * default) and collects the signatures that are pending at the providers.
 */
final class ProcessSignatureLifecycleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->afterCommit();
    }

    public function handle(EnvelopeService $envelopes): void
    {
        $envelopes->processLifecycle();
    }
}
