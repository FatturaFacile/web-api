<?php

declare(strict_types=1);

use App\Models\Concerns\BelongsToTenant;
use App\Models\EiInboundLog;
use App\Models\PasswordHistory;
use App\Models\TenantAccessRequest;
use App\Services\Tenancy\TenantSchemaPolicy;
use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;
use Enterprise\MultiTenant\Concerns\StampsTenantFromParent;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\Finder\Finder;

arch('api controllers do not use raw DB facade or DatabaseManager')
    ->expect('App\Http\Controllers\Api')
    ->not->toUse([
        'Illuminate\Support\Facades\DB',
        'Illuminate\Database\DatabaseManager',
    ]);

test('api controllers never call DB facade directly', function (): void {
    $apiControllersPath = app_path('Http/Controllers/Api');

    if (! is_dir($apiControllersPath)) {
        return;
    }

    $finder = (new Finder)
        ->in($apiControllersPath)
        ->files()
        ->name('*.php');

    $violations = [];

    foreach ($finder as $file) {
        $content = $file->getContents();

        if (preg_match('/\bDB::|\buse\s+Illuminate\\\\Support\\\\Facades\\\\DB\b/', $content)) {
            $violations[] = $file->getRelativePathname();
        }
    }

    expect($violations)->toBeEmpty(
        'Direct DB facade usage detected in API controllers: '.implode(', ', $violations)
    );
});

test('concrete domain models use BelongsToTenant trait', function (): void {
    $modelsPath = app_path('Models');

    if (! is_dir($modelsPath)) {
        return;
    }

    $finder = (new Finder)
        ->in($modelsPath)
        ->files()
        ->name('*.php');

    /**
     * Models outside the trait besides the framework and central tables of TenantSchemaPolicy: they filter by tenant in
     * their own code (the entries of TenantModelCoverageTest explain why).
     */
    $excludedModels = [
        TenantAccessRequest::class,
        EiInboundLog::class,
    ];

    $nonCompliant = [];

    foreach ($finder as $file) {
        // Skip subdirectories (e.g. Scopes, Concerns, Enums)
        $relativePath = str_replace('\\', '/', $file->getRelativePathname());
        if (str_contains($relativePath, '/')) {
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

        if (in_array($className, $excludedModels, true) || TenantSchemaPolicy::isExcluded((new $className)->getTable())) {
            continue;
        }

        $traits = class_uses_recursive($className);
        if (! in_array(BelongsToTenant::class, $traits, true) && ! in_array(StampsTenantFromParent::class, $traits, true)) {
            $nonCompliant[] = $className;
        }
    }

    expect($nonCompliant)->toBeEmpty(
        'The following concrete models are missing BelongsToTenant (or StampsTenantFromParent for a child table): '.implode(', ', $nonCompliant)
    );
});

test('PasswordHistory is global user data: it uses the tenant policy and allows global records', function (): void {
    expect(class_uses_recursive(PasswordHistory::class))->toContain(BelongsToTenant::class, AllowsGlobalRecords::class)
        ->and(PasswordHistory::allowsGlobalRecords())->toBeTrue();
});
