<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Symfony\Component\Finder\Finder;

class GenerateMissingTenantMigrations extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenancy:generate-missing-columns {--foreign : Adds a foreign key constraint referencing tenants.id}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scans models using BelongsToTenant and generates a migration for tables missing the tenant_id column';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $missingTables = $this->findTablesMissingTenantColumn();

        if (empty($missingTables)) {
            $this->info('All tables mapped to multi-tenant models already have the tenant_id column.');

            return self::SUCCESS;
        }

        $this->warn('Tables missing the tenant_id column:');
        foreach ($missingTables as $table) {
            $this->line("- {$table}");
        }

        $withForeign = (bool) $this->option('foreign');
        $migrationContent = $this->buildMigrationContent($missingTables, $withForeign);

        $timestamp = date('Y_m_d_His');
        $fileName = "{$timestamp}_add_tenant_id_to_missing_tenant_tables.php";
        $path = database_path("migrations/{$fileName}");

        File::put($path, $migrationContent);

        $this->info("\nMigration created successfully at: database/migrations/{$fileName}");
        $this->comment('Run "php artisan migrate" to apply the changes.');

        return self::SUCCESS;
    }

    /**
     * Scan domain models and identify tables lacking the tenant_id column.
     *
     * @return array<string>
     */
    private function findTablesMissingTenantColumn(): array
    {
        $modelsPath = app_path('Models');
        $finder = (new Finder)->files()->in($modelsPath)->name('*.php');
        $missing = [];

        foreach ($finder as $file) {
            // Skip subdirectories (e.g., Scopes, Concerns, Enums)
            if (str_contains($file->getRelativePathname(), DIRECTORY_SEPARATOR)) {
                continue;
            }

            $class = 'App\\Models\\'.$file->getFilenameWithoutExtension();
            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
                continue;
            }

            if (! in_array(BelongsToTenant::class, class_uses_recursive($class), true)) {
                continue;
            }

            /** @var Model $modelInstance */
            $modelInstance = new $class;
            $table = $modelInstance->getTable();

            // Verify table existence and check if tenant_id is missing
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'tenant_id')) {
                $missing[$table] = $table;
            }
        }

        return array_values($missing);
    }

    /**
     * Build the raw migration file content with up() and down() methods.
     *
     * @param  array<string>  $tables
     */
    private function buildMigrationContent(array $tables, bool $withForeign): string
    {
        $upStatements = '';
        $downStatements = '';

        foreach ($tables as $table) {
            if ($withForeign) {
                $upStatements .= <<<PHP
        Schema::table('{$table}', function (Blueprint \$table): void {
            \$table->foreignId('tenant_id')
                ->nullable()
                ->after('id')
                ->constrained('tenants')
                ->cascadeOnDelete();
        });

PHP;
                $downStatements .= <<<PHP
        Schema::table('{$table}', function (Blueprint \$table): void {
            \$table->dropConstrainedForeignId('tenant_id');
        });

PHP;
            } else {
                $upStatements .= <<<PHP
        Schema::table('{$table}', function (Blueprint \$table): void {
            \$table->foreignId('tenant_id')
                ->nullable()
                ->after('id')
                ->index();
        });

PHP;
                $downStatements .= <<<PHP
        Schema::table('{$table}', function (Blueprint \$table): void {
            \$table->dropIndex(['tenant_id']);
            \$table->dropColumn('tenant_id');
        });

PHP;
            }
        }

        return <<<PHP
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
{$upStatements}    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
{$downStatements}    }
};
PHP;
    }
}
