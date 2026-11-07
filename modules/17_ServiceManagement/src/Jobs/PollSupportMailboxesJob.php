<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\ServiceManagement\Models\SupportMailbox;
use Modules\ServiceManagement\Services\Helpdesk\Email\SupportMailboxPoller;

/**
 * Polls the active support mailboxes of a tenant, or a single one. Runs in the tenant context the scheduler set.
 */
final class PollSupportMailboxesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public function __construct(
        public readonly string $tenantId,
        public readonly ?string $mailboxId = null,
    ) {}

    public function handle(SupportMailboxPoller $poller): void
    {
        $mailboxes = SupportMailbox::query()
            ->where('tenant_id', $this->tenantId)
            ->where('is_active', true)
            ->when($this->mailboxId !== null, fn ($query) => $query->whereKey($this->mailboxId))
            ->get();

        foreach ($mailboxes as $mailbox) {
            $poller->poll($mailbox);
        }
    }
}
