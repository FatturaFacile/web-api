<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Concerns\BelongsToTenant as AppBelongsToTenant;
use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Enterprise\MultiTenant\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

/**
 * Discovers every concrete Eloquent model of the application, of the modules and of the internal packages.
 *
 * The registry drives the generic tenant isolation tests: a new model is picked up with no change to the tests, so a
 * model added without the tenant scope (or without a documented exception) fails the architecture test.
 */
final class TenantModelRegistry
{
    /**
     * @var list<array{class: class-string<Model>, table: string, hasTenantColumn: bool, scoped: bool, hasFactory: bool}>|null
     */
    private static ?array $models = null;

    /**
     * @return list<array{class: class-string<Model>, table: string, hasTenantColumn: bool, scoped: bool, hasFactory: bool}>
     */
    public static function models(): array
    {
        if (self::$models !== null) {
            return self::$models;
        }

        $models = [];
        $seen = [];

        foreach (self::sourceDirectories() as $directory) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS));

            foreach ($iterator as $file) {
                $path = str_replace('\\', '/', $file->getPathname());

                if ($file->getExtension() !== 'php' || ! preg_match('~(^|/)Models/~', $path)) {
                    continue;
                }

                $source = (string) file_get_contents($file->getPathname());

                if (! str_contains($source, 'extends')
                    || ! preg_match('/^namespace\s+([^;]+);/m', $source, $namespace)
                    || ! preg_match('/^(?:final\s+|abstract\s+)?class\s+(\w+)/m', $source, $class)) {
                    continue;
                }

                $className = $namespace[1].'\\'.$class[1];

                if (isset($seen[$className]) || ! class_exists($className)) {
                    continue;
                }

                $seen[$className] = true;
                $reflection = new ReflectionClass($className);

                if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
                    continue;
                }

                /** @var Model $instance */
                $instance = new $className;
                $table = $instance->getTable();
                $traits = class_uses_recursive($className);

                $scoped = false;

                foreach ($instance->getGlobalScopes() as $scope) {
                    if ($scope instanceof TenantScope) {
                        $scoped = true;
                    }
                }

                $models[] = [
                    'class' => $className,
                    'table' => $table,
                    'hasTenantColumn' => Schema::hasColumn($table, 'tenant_id'),
                    'scoped' => $scoped || in_array(BelongsToTenant::class, $traits, true) || in_array(AppBelongsToTenant::class, $traits, true),
                    'hasFactory' => method_exists($className, 'factory'),
                ];
            }
        }

        usort($models, static fn (array $a, array $b): int => strcmp($a['class'], $b['class']));

        return self::$models = $models;
    }

    /**
     * @return list<string>
     */
    private static function sourceDirectories(): array
    {
        $directories = [base_path('app'), base_path('packages')];

        foreach (glob(base_path('modules/*/src')) ?: [] as $directory) {
            $directories[] = $directory;
        }

        return $directories;
    }
}
