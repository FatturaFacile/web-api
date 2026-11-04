<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Rebuilds the public demo from scratch: empties the storage disks (attachments, PDFs, signatures, backups), drops
 * every table, runs the migrations, then runs the full `erp:bootstrap` (reference data, users and the integrated
 * cross-module demo of `tenant_demo`). Scheduled every 30 minutes so that nothing visitors enter persists.
 *
 * While the tables are being rebuilt the application is in maintenance mode: API requests get a 503 with Retry-After
 * and the queue worker pauses instead of polling a `jobs` table that does not exist for a few seconds. Maintenance
 * mode is lifted at the end, even when a step fails, unless it was already active before the reset.
 *
 * It destroys all data, so it only runs where `DEMO_RESET_ENABLED=true` (config `app.demo_reset`).
 */
class DemoResetCommand extends Command
{
    protected $signature = 'demo:reset';

    protected $description = 'Rebuild the demo database and empty its storage (only where DEMO_RESET_ENABLED=true)';

    /** Seconds suggested to API clients (Retry-After header) while the demo is being rebuilt. */
    private const RETRY_AFTER_SECONDS = 60;

    public function handle(): int
    {
        if (! config('app.demo_reset')) {
            $this->error('Demo reset is disabled: set DEMO_RESET_ENABLED=true only on the demo environment.');

            return self::FAILURE;
        }

        $maintenance = $this->laravel->maintenanceMode();
        $wasDown = $maintenance->active();

        if (! $wasDown) {
            $maintenance->activate(['retry' => self::RETRY_AFTER_SECONDS, 'status' => 503]);
        }

        try {
            return $this->rebuild();
        } finally {
            if (! $wasDown) {
                $maintenance->deactivate();
            }
        }
    }

    private function rebuild(): int
    {
        // Files first: the bootstrap below may write new ones
        foreach ($this->storageDisks() as $disk) {
            $this->emptyDisk($disk);
        }

        $steps = [
            ['migrate:fresh', ['--force' => true]],
            ['erp:bootstrap', ['--force' => true]],
        ];

        foreach ($steps as [$command, $arguments]) {
            if ($this->call($command, $arguments) !== self::SUCCESS) {
                $this->error("Demo reset stopped: {$command} failed.");

                return self::FAILURE;
            }
        }

        $this->info('Demo rebuilt.');

        return self::SUCCESS;
    }

    /**
     * Every disk that holds tenant data: uploads and generated files, backup archives and the public disk.
     *
     * @return list<string>
     */
    private function storageDisks(): array
    {
        $disks = [
            (string) config('filesystems.private_disk', config('filesystems.default', 'local')),
            (string) config('backup.disk'),
            (string) config('backup.files_disk'),
            'public',
        ];

        return array_values(array_unique(array_filter(
            $disks,
            fn (string $disk): bool => array_key_exists($disk, (array) config('filesystems.disks')),
        )));
    }

    private function emptyDisk(string $disk): void
    {
        $storage = Storage::disk($disk);

        foreach ($storage->directories() as $directory) {
            $storage->deleteDirectory($directory);
        }

        // Keep .gitignore, which keeps the storage folders in the repository
        $storage->delete(array_filter($storage->files(), fn (string $file): bool => $file !== '.gitignore'));

        $this->line("Storage disk '{$disk}' emptied.");
    }
}
