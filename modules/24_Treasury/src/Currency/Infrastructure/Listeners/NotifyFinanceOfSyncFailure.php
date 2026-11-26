<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Infrastructure\Listeners;

use App\Models\Tenant;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Modules\Treasury\Currency\Domain\Events\ExchangeRateSyncFailed;
use Modules\Treasury\Currency\Infrastructure\Notifications\ExchangeRateSyncFailedNotification;
use Throwable;

/**
 * Tells the CFO, administrators and accountants (Mod 008 notifications) that a synchronisation failed: the company that asked
 * for a manual run, or every company for a scheduled run. A failed notification never hides the failed run itself.
 */
final class NotifyFinanceOfSyncFailure
{
    public function handle(ExchangeRateSyncFailed $event): void
    {
        try {
            $roles = (array) config('currency_fx.notify_roles', ['owner', 'admin']);
            $tenants = $event->tenantId === null ? Tenant::query()->get() : Tenant::query()->whereKey($event->tenantId)->get();

            foreach ($tenants as $tenant) {
                $recipients = $tenant->users()->wherePivotIn('role', $roles)->get();

                if ($recipients->isNotEmpty()) {
                    Notification::send($recipients, new ExchangeRateSyncFailedNotification($event->runId, $event->provider, $event->error));
                }
            }
        } catch (Throwable $exception) {
            Log::warning('Rate sync failure not notified.', ['run' => $event->runId, 'reason' => $exception->getMessage()]);
        }
    }
}
