<?php

declare(strict_types=1);

namespace Tests\Feature\Manufacturing;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Modules\Manufacturing\Contracts\BillOfMaterialsServiceInterface;
use Modules\Manufacturing\Contracts\ManufacturingForecastingServiceInterface;
use Modules\Manufacturing\Contracts\MrpServiceInterface;
use Modules\Manufacturing\Contracts\ProductionCostingServiceInterface;
use Modules\Manufacturing\Contracts\ProductionExecutionServiceInterface;
use Modules\Manufacturing\Contracts\ProductionPlanningServiceInterface;
use Modules\Manufacturing\Contracts\QualityManagementServiceInterface;
use Modules\Manufacturing\Contracts\RoutingServiceInterface;
use Modules\Manufacturing\Contracts\ShopFloorServiceInterface;
use Modules\Manufacturing\Providers\ManufacturingServiceProvider;
use ReflectionClass;
use ReflectionMethod;

/**
 * The PHP files of a folder of the module.
 *
 * @return list<string>
 */
function mfgModuleFiles(string $folder): array
{
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('modules/30_Manufacturing/src/'.$folder)));
    $files = [];
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    return $files;
}

/**
 * The class declared in a module file.
 */
function mfgClassOf(string $file): string
{
    $relative = str_replace(['/', '.php'], ['\\', ''], substr(str_replace('\\', '/', $file), strlen(str_replace('\\', '/', base_path('modules/30_Manufacturing/src/')))));

    return 'Modules\\Manufacturing\\'.$relative;
}

test('Manufacturing module service provider is registered and resolves all service contracts', function (): void {
    expect(app()->providerIsLoaded(ManufacturingServiceProvider::class))->toBeTrue();

    foreach ([BillOfMaterialsServiceInterface::class, RoutingServiceInterface::class, MrpServiceInterface::class, ProductionPlanningServiceInterface::class, ProductionExecutionServiceInterface::class, ShopFloorServiceInterface::class, QualityManagementServiceInterface::class, ProductionCostingServiceInterface::class, ManufacturingForecastingServiceInterface::class] as $contract) {
        expect(app($contract))->toBeInstanceOf($contract);
    }
});

test('Manufacturing models extend BaseEnterpriseModel and events implement DomainEventInterface', function (): void {
    foreach (mfgModuleFiles('Models') as $file) {
        expect((new ReflectionClass(mfgClassOf($file)))->isSubclassOf(BaseEnterpriseModel::class))->toBeTrue($file);
    }

    foreach (mfgModuleFiles('Events') as $file) {
        $reflection = new ReflectionClass(mfgClassOf($file));
        expect($reflection->isAbstract() || $reflection->implementsInterface(DomainEventInterface::class))->toBeTrue($file);
    }
});

test('every class and public method of the module is documented', function (): void {
    $undocumented = [];

    foreach (mfgModuleFiles('') as $file) {
        $reflection = new ReflectionClass(mfgClassOf($file));
        if ($reflection->getDocComment() === false) {
            $undocumented[] = $reflection->getName();
        }

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $reflection->getName() || realpath((string) $method->getFileName()) !== realpath($file) || $method->isConstructor()) {
                continue;
            }
            if ($method->getDocComment() === false) {
                $undocumented[] = $reflection->getName().'::'.$method->getName();
            }
        }
    }

    expect($undocumented)->toBe([]);
});

test('the module computes costs only from recorded data: no estimated rates, percentages or fallback prices', function (): void {
    $forbidden = ['/\*\s*0\.9\d\b/', '/\*\s*0\.0\d\b/', '/\*\s*0\.35\b/', '/\*\s*15\.00\b/', '/\*\s*45\.0\b/', '/= 25\.0000/', '/= 45\.0000/', '/: 10\.0;/', "/'EUR'/", '/019318b7-0000-7000-8000-000000000001/'];
    $offending = [];

    foreach (mfgModuleFiles('') as $file) {
        $source = (string) file_get_contents($file);
        foreach ($forbidden as $pattern) {
            if (preg_match($pattern, $source) === 1) {
                $offending[] = basename($file).' '.$pattern;
            }
        }
    }

    expect($offending)->toBe([]);
});

test('stock moves only through the inventory services: the module never writes stock tables', function (): void {
    $offending = [];
    foreach (mfgModuleFiles('') as $file) {
        $source = (string) file_get_contents($file);
        if (preg_match("/(StockMovement::(create|query\(\)->(insert|update))|StockQuant::.*(increment|decrement|update)|table\('stock_(movements|quants)'\))/", $source) === 1) {
            $offending[] = basename($file);
        }
    }

    expect($offending)->toBe([]);
});
