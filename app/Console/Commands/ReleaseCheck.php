<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Checks that the environment is ready for a production release: safe configuration, asynchronous queue, scheduler,
 * storage, database and the generated documentation. It exits with a failure when a check fails.
 */
class ReleaseCheck extends Command
{
    protected $signature = 'release:check';

    protected $description = 'Check that the application is ready for a production release';

    public function handle(Schedule $schedule): int
    {
        $counts = ['ok' => 0, 'warning' => 0, 'fail' => 0];
        $this->line('<info>'.__('release.title', ['version' => config('release.version')]).'</info>');

        foreach ($this->checks($schedule) as [$status, $label]) {
            $counts[$status]++;
            "release.status.{$status}"
                |> __(...)
                |> (fn ($x) => sprintf('[%s] %s', $x, $label))
                |> $this(...);
        }

        $this->newLine();
        $this->line(__('release.summary', ['failed' => $counts['fail'], 'warnings' => $counts['warning'], 'ok' => $counts['ok']]));

        if ($counts['fail'] > 0) {
            $this->error(__('release.not_ready'));

            return self::FAILURE;
        }

        $this->info(__('release.ready'));

        return self::SUCCESS;
    }

    /**
     * @return array<int, array{0: string, 1: string}> status (`ok`, `warning`, `fail`) and label of every check
     */
    private function checks(Schedule $schedule): array
    {
        $queue = (string) config('queue.default');
        $cache = (string) config('cache.default');
        $disk = (string) config('backup.disk');
        $backupScheduled = collect($schedule->events())->contains(fn ($event): bool => str_contains((string) $event->command, 'tenants:backup'));

        return [
            [app()->environment('production') ? 'ok' : 'warning', __('release.checks.environment', ['value' => app()->environment()])],
            [config('app.debug') ? 'fail' : 'ok', __('release.checks.debug')],
            [config('app.key') ? 'ok' : 'fail', __('release.checks.key')],
            [$queue === 'sync' ? 'fail' : 'ok', __('release.checks.queue', ['value' => $queue])],
            [in_array($cache, ['array', 'null'], true) ? 'warning' : 'ok', __('release.checks.cache', ['value' => $cache])],
            [$backupScheduled ? 'ok' : 'fail', __('release.checks.scheduler')],
            [array_key_exists($disk, (array) config('filesystems.disks')) ? 'ok' : 'fail', __('release.checks.backup_disk', ['disk' => $disk])],
            [is_writable(storage_path('app')) && is_writable(storage_path('framework')) && is_writable(storage_path('logs')) ? 'ok' : 'fail', __('release.checks.storage')],
            [$this->databaseReady() ? 'ok' : 'fail', __('release.checks.database')],
            [is_file((string) config('docs.spec_path')) ? 'ok' : 'fail', __('release.checks.docs')],
            [app()->configurationIsCached() ? 'ok' : 'warning', __('release.checks.config_cache')],
            [app()->routesAreCached() ? 'ok' : 'warning', __('release.checks.route_cache')],
        ];
    }

    private function databaseReady(): bool
    {
        try {
            DB::select('select 1');

            return Schema::hasTable('migrations') && Schema::hasTable('tenants');
        } catch (Throwable) {
            return false;
        }
    }
}
