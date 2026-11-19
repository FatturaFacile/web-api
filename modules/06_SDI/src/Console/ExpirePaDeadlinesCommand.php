<?php

declare(strict_types=1);

namespace Modules\SDI\Console;

use Illuminate\Console\Command;
use Modules\SDI\Services\SdiNotificationProcessorService;

final class ExpirePaDeadlinesCommand extends Command
{
    protected $signature = 'sdi:expire-pa-deadlines';

    protected $description = 'Move public administration invoices unanswered for 15 days to decorrenza termini (DT)';

    public function handle(SdiNotificationProcessorService $processor): int
    {
        $this->info('Expired: '.$processor->expirePaDeadlines());

        return self::SUCCESS;
    }
}
