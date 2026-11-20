<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Infrastructure\Console;

use Illuminate\Console\Command;
use Modules\DigitalPreservation\Infrastructure\Jobs\VerifyPreservationIntegrityJob;

/**
 * Re-verifies the preserved files and the hash chains.
 */
final class PreservationVerifyCommand extends Command
{
    protected $signature = 'fatturafacile:preservation:verify {--tenant= : Only this tenant id}';

    protected $description = 'Re-hash the preserved files and verify the Merkle roots, batch chain and audit chain';

    /**
     * @var list<string>
     */
    protected $aliases = ['preservation:verify-integrity'];

    public function handle(): int
    {
        VerifyPreservationIntegrityJob::dispatchSync($this->option('tenant') ?: null);
        $this->info('Preservation integrity verification completed.');

        return self::SUCCESS;
    }
}
