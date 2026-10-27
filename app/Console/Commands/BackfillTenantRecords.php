<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Symfony\Component\Finder\Finder;

class BackfillTenantRecords extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenancy:backfill
                            {tenant_id : The target tenant ID to associate legacy records with}
                            {--chunk=500 : The number of records to update per batch}
                            {--model= : Specific model class to backfill (e.g. "App\Models\ProductionOrder")}
                            {--dry-run : Preview affected records without writing changes to the database}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfills null tenant_id attributes on legacy records for models using BelongsToTenant';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $targetTenantId = $this->argument('tenant_id');
        $chunkSize = (int) $this->option('chunk');
        $isDryRun = (bool) $this->option('dry-run');
        $specificModel = $this->option('model');

        // Validate target tenant existence
        if (! Tenant::where('id', $targetTenantId)->exists()) {
            $this->error(sprintf('Target tenant with ID "%s" does not exist.', $targetTenantId));

            return self::FAILURE;
        }

        $models = $specificModel !== null
            ? [$specificModel]
            : $this->discoverMultiTenantModels();

        if (empty($models)) {
            $this->warn('No eligible multi-tenant models found.');

            return self::SUCCESS;
        }

        if ($isDryRun) {
            $this->info('--- DRY RUN MODE (No database writes will be executed) ---');
        }

        $this->info(sprintf('Starting tenant backfill process for target Tenant ID: %s', $targetTenantId));

        $totalUpdatedOverall = 0;

        foreach ($models as $modelClass) {
            if (! class_exists($modelClass)) {
                $this->error(sprintf('Class "%s" not found. Skipping.', $modelClass));

                continue;
            }

            $totalUpdatedOverall += $this->backfillModel($modelClass, $targetTenantId, $chunkSize, $isDryRun);
        }

        $this->newLine();
        $this->info(sprintf(
            'Backfill completed successfully. Total records %s: %d',
            $isDryRun ? 'eligible for update' : 'updated',
            $totalUpdatedOverall
        ));

        return self::SUCCESS;
    }

    /**
     * Backfill legacy records for a given model class.
     *
     * @param  class-string<Model>  $modelClass
     */
    private function backfillModel(string $modelClass, mixed $targetTenantId, int $chunkSize, bool $isDryRun): int
    {
        /** @var Model $instance */
        $instance = new $modelClass;
        $table = $instance->getTable();

        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'tenant_id')) {
            $this->line(sprintf('Table "%s" does not exist or lacks tenant_id column. Skipping.', $table));

            return 0;
        }

        // Bypass all global scopes (including TenantScope and SoftDeletes)
        $query = $modelClass::withoutGlobalScopes()
            ->whereNull('tenant_id');

        $pendingCount = (clone $query)->count();

        if ($pendingCount === 0) {
            $this->line(sprintf('[%s] No orphaned records found.', class_basename($modelClass)));

            return 0;
        }

        $this->warn(sprintf(
            '[%s] Found %d records with NULL tenant_id.',
            class_basename($modelClass),
            $pendingCount
        ));

        if ($isDryRun) {
            return $pendingCount;
        }

        $updatedCount = 0;

        // Process in chunks to prevent memory overhead and lock contention
        DB::transaction(function () use ($query, $targetTenantId, $chunkSize, &$updatedCount): void {
            do {
                $affected = (clone $query)
                    ->limit($chunkSize)
                    ->update(['tenant_id' => $targetTenantId]);

                $updatedCount += $affected;
            } while ($affected > 0);
        });

        $this->info(sprintf('[%s] Successfully assigned %d records to Tenant ID "%s".', class_basename($modelClass), $updatedCount, $targetTenantId));

        return $updatedCount;
    }

    /**
     * Discover all concrete models in app/Models implementing BelongsToTenant.
     *
     * @return array<class-string<Model>>
     */
    private function discoverMultiTenantModels(): array
    {
        $modelsPath = app_path('Models');
        $finder = (new Finder)->files()->in($modelsPath)->name('*.php');
        $models = [];

        foreach ($finder as $file) {
            if (str_contains($file->getRelativePathname(), DIRECTORY_SEPARATOR)) {
                continue;
            }

            /** @var class-string $class */
            $class = 'App\\Models\\'.$file->getFilenameWithoutExtension();
            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
                continue;
            }

            if (in_array(BelongsToTenant::class, class_uses_recursive($class), true)) {
                $models[] = $class;
            }
        }

        return $models;
    }
}
