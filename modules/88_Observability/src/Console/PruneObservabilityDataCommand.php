<?php

declare(strict_types=1);

namespace Modules\Observability\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Observability\Profiler\ProfilerSettings;

/**
 * Daily retention of the diagnostics tables (slow queries, endpoint samples, connector checks); the retention days are a platform setting.
 * It never touches business or statutory data.
 */
final class PruneObservabilityDataCommand extends Command
{
    protected $signature = 'observability:prune {--days= : Override the retention in days}';

    protected $description = 'Deletes observability diagnostics older than the retention period';

    public function handle(ProfilerSettings $settings): int
    {
        $days = max(1, (int) ($this->option('days') ?: $settings->all()['retention_days']));
        $limit = now()->subDays($days);
        $deleted = 0;

        foreach (['observability_slow_queries' => 'captured_at', 'observability_endpoint_samples' => 'captured_at', 'observability_connector_checks' => 'checked_at'] as $table => $column) {
            $deleted += DB::table($table)->where($column, '<', $limit)->delete();
        }

        $this->line("{$deleted} rows older than {$days} days deleted");

        return self::SUCCESS;
    }
}
