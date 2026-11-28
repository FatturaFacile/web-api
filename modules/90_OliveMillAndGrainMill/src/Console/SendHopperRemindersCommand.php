<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Console;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Modules\OliveMillAndGrainMill\Application\Services\SmsReminderService;
use Throwable;

/**
 * Sends the hopper SMS reminder ("your lot enters the hopper in 45 minutes") of every company whose milling slot is about to start. A company that
 * fails does not stop the others.
 */
final class SendHopperRemindersCommand extends Command
{
    protected $signature = 'olive-mill:send-reminders {--tenant= : Remind one company only}';

    protected $description = 'Send the hopper SMS reminders of the milling slots that start within the lead time';

    public function handle(SmsReminderService $reminders, TenantContext $context): int
    {
        $tenants = Tenant::query()->when($this->option('tenant'), static fn ($query, string $id) => $query->whereKey($id))->get();
        $sent = 0;
        $failed = 0;

        foreach ($tenants as $tenant) {
            try {
                $sent += $context->runWithTenant($tenant, static fn (): int => $reminders->sendDue());
            } catch (Throwable $exception) {
                $failed++;
                report($exception);
                $this->error($tenant->id.': '.$exception->getMessage());
            }
        }

        $this->info($sent.' reminder(s) sent.');

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
