<?php

declare(strict_types=1);

namespace Modules\Hse\Console;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Modules\Hse\Infrastructure\Services\HseExpirationScanner;

/**
 * Daily HSE expiration scan: alerts the HR manager, the RSPP/SiBe and the line manager at 60, 30 and 7 days before a
 * certificate, a medical visit or a PPE replacement expires, and every day after.
 */
final class CheckHseExpirationsCommand extends Command
{
    protected $signature = 'hse:check-expirations {--tenant= : Only this tenant id} {--date= : Run as of this day (Y-m-d), default today}';

    protected $description = 'Send the 60/30/7 day and overdue HSE expiration alerts';

    public function handle(HseExpirationScanner $scanner, TenantContext $tenants): int
    {
        $today = $this->option('date') !== null ? CarbonImmutable::parse((string) $this->option('date')) : CarbonImmutable::today();
        $sent = 0;

        Tenant::query()->when($this->option('tenant') !== null, fn ($query) => $query->whereKey((string) $this->option('tenant')))
            ->each(function (Tenant $tenant) use ($scanner, $tenants, $today, &$sent): void {
                $sent += (int) $tenants->runWithTenant($tenant, fn (): int => $scanner->scan((string) $tenant->id, $today));
            });

        $this->info("HSE expiration alerts sent: {$sent}");

        return self::SUCCESS;
    }
}
