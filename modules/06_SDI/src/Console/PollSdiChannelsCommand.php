<?php

declare(strict_types=1);

namespace Modules\SDI\Console;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Modules\SDI\Enums\SdiChannel;
use Modules\SDI\Jobs\PollSdiChannelJob;

final class PollSdiChannelsCommand extends Command
{
    protected $signature = 'sdi:poll';

    protected $description = 'Read receipts and supplier invoices from the SDI channel of every tenant';

    public function handle(): int
    {
        $tenants = Tenant::whereNotNull('sdi_channel')->where('sdi_channel', '!=', SdiChannel::Local->value)->pluck('id');

        $tenants->each(fn (string $tenantId) => PollSdiChannelJob::dispatch($tenantId));

        $this->info("Queued {$tenants->count()} SDI channel reads.");

        return self::SUCCESS;
    }
}
