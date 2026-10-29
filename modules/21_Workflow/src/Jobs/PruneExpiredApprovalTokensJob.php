<?php

declare(strict_types=1);

namespace Modules\Workflow\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Workflow\Models\ApprovalToken;

final class PruneExpiredApprovalTokensJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->afterCommit = true;
    }

    public function handle(): void
    {
        ApprovalToken::where('expires_at', '<', now()->subDays(30))
            ->orWhereNotNull('used_at')
            ->where('used_at', '<', now()->subDays(30))
            ->delete();
    }
}
