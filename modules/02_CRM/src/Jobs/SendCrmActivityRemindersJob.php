<?php

declare(strict_types=1);

namespace Modules\CRM\Jobs;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\CRM\Models\Activity;
use Modules\CRM\Notifications\ActivityReminderNotification;

/**
 * Scheduled per tenant: notifies the assignee of every open CRM activity whose reminder time has come, once. The
 * reminder is marked as sent before the notification is queued, so overlapping runs never remind twice.
 */
final class SendCrmActivityRemindersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly string $tenantId)
    {
        $this->afterCommit();
    }

    public function handle(): void
    {
        Activity::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->whereNull('deleted_at')
            ->whereNotNull('reminder_at')
            ->whereNull('reminder_sent_at')
            ->where('reminder_at', '<=', now())
            ->whereIn('status', ['pending', 'in_progress'])
            ->whereNotNull('assigned_user_id')
            ->orderBy('reminder_at')
            ->chunkById(100, function ($activities): void {
                foreach ($activities as $activity) {
                    $claimed = Activity::withoutGlobalScopes()
                        ->where('id', $activity->id)
                        ->whereNull('reminder_sent_at')
                        ->update(['reminder_sent_at' => now()]);

                    $assignee = User::find($activity->assigned_user_id);

                    if ($claimed === 1 && $assignee !== null && $assignee->tenants()->whereKey($this->tenantId)->exists()) {
                        $assignee->notify(new ActivityReminderNotification($activity));
                    }
                }
            });
    }
}
