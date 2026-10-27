<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

class TenantSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_tables_mapped_to_belongs_to_tenant_models_have_tenant_id_column(): void
    {
        $models = $this->discoverBelongsToTenantModels();
        $this->assertNotEmpty($models, 'No models using BelongsToTenant were discovered.');

        foreach ($models as $className => $table) {
            $this->assertTrue(
                Schema::hasColumn($table, 'tenant_id'),
                "Table '{$table}' (mapped to model '{$className}') is missing the 'tenant_id' column."
            );
        }
    }

    public function test_all_tables_mapped_to_belongs_to_tenant_models_have_index_on_tenant_id(): void
    {
        $models = $this->discoverBelongsToTenantModels();
        $this->assertNotEmpty($models, 'No models using BelongsToTenant were discovered.');

        $missingIndexes = [];

        foreach ($models as $className => $table) {
            $indexes = Schema::getIndexes($table);
            $hasTenantIndex = false;

            foreach ($indexes as $index) {
                $columns = $index['columns'] ?? [];
                // Assert that tenant_id is indexed either as a standalone single-column index or leading column
                if (! empty($columns) && $columns[0] === 'tenant_id') {
                    $hasTenantIndex = true;
                    break;
                }
            }

            if (! $hasTenantIndex) {
                $missingIndexes[] = "Table '{$table}' (Model '{$className}') is missing an index with 'tenant_id' as the leading column.";
            }
        }

        $this->assertEmpty(
            $missingIndexes,
            "The following multi-tenant tables lack an index on 'tenant_id':\n".implode("\n", $missingIndexes)
        );
    }

    /**
     * Dynamically discover all Eloquent models in app/Models using BelongsToTenant.
     *
     * @return array<string, string> Map of Model class name => Table name
     */
    private function discoverBelongsToTenantModels(): array
    {
        $finder = (new Finder)
            ->in(app_path('Models'))
            ->files()
            ->name('*.php');

        $models = [];

        foreach ($finder as $file) {
            // Exclude sub-namespaces like Scopes, Concerns
            if (str_contains($file->getRelativePathname(), DIRECTORY_SEPARATOR)) {
                continue;
            }

            $className = 'App\\Models\\'.$file->getFilenameWithoutExtension();
            if (! class_exists($className)) {
                continue;
            }

            $reflection = new ReflectionClass($className);
            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
                continue;
            }

            $traits = class_uses_recursive($className);
            if (in_array(BelongsToTenant::class, $traits, true)) {
                /** @var Model $modelInstance */
                $modelInstance = new $className;
                $models[$className] = $modelInstance->getTable();
            }
        }

        return $models;
    }
}
