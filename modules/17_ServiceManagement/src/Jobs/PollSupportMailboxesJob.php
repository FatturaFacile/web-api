<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\ServiceManagement\Models\SupportMailbox;
use Modules\ServiceManagement\Services\Helpdesk\Email\SupportMailboxPoller;

/**
 * Polls the active support mailboxes of a tenant, or a single one. Runs in the tenant context the scheduler set.
 *
 * The scheduler starts it every two minutes while a slow mailbox can keep it busy far longer: two polls of the same
 * mailbox at once would open every new e-mail as two tickets, so only one poll per tenant is queued or running.
 */
final class PollSupportMailboxesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    /**
     * The next scheduled run polls again: retrying here would only overlap with it.
     */
    public int $tries = 1;

    /**
     * Released by the end of the poll; this only bounds a lock left behind by a worker that died.
     */
    public int $uniqueFor = 600;

    public function __construct(
        public readonly string $tenantId,
        public readonly ?string $mailboxId = null,
    ) {}

    public function uniqueId(): string
    {
        return $this->tenantId;
    }

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
