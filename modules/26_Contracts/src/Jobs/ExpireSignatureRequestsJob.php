<?php

declare(strict_types=1);

namespace Modules\Contracts\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Contracts\Services\SignatureService;

/**
 * Queued job: mark the sent e-signature requests of a company whose expiry date has passed as expired.
 */
final class ExpireSignatureRequestsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly string $tenantId)
    {
        $this->afterCommit();
    }

    public function handle(SignatureService $signatures): void
    {
        $signatures->expireOverdue($this->tenantId, CarbonImmutable::today());
    }
}
