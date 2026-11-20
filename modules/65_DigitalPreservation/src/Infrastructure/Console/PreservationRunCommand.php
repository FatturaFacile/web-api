<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Infrastructure\Console;

use Illuminate\Console\Command;
use Modules\DigitalPreservation\Infrastructure\Jobs\AutoIngestPreservationJob;

/**
 * Collects, seals, time-stamps and dispatches the unpreserved documents of the tenants.
 */
final class PreservationRunCommand extends Command
{
    protected $signature = 'fatturafacile:preservation:run {--tenant= : Only this tenant id} {--force : Ignore the schedule frequency}';

    protected $description = 'Collect unpreserved documents into batches, seal, time-stamp and dispatch them to the conservator';

    /**
     * @var list<string>
     */
    protected $aliases = ['preservation:auto-ingest'];

    public function handle(): int
    {
        AutoIngestPreservationJob::dispatchSync($this->option('tenant') ?: null, (bool) $this->option('force'));
        $this->info('Preservation run completed.');

        return self::SUCCESS;
    }
}
