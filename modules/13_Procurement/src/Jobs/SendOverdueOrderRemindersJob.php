<?php

declare(strict_types=1);

namespace Modules\Procurement\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Procurement\Services\OverdueOrderReminderService;

/**
 * Daily reminder run of a company: supplier reminders for the purchase orders past their delivery date.
 */
final class SendOverdueOrderRemindersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly ?string $tenantId = null,
    ) {
        $this->afterCommit();
    }

    public function handle(OverdueOrderReminderService $reminders): void
    {
        $reminders->sendDue($this->tenantId);
    }
}
