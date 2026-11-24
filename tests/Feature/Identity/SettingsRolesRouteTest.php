<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Modules\Platform\Identity\Http\Controllers\IdentityRoleController;
use OpenApi\Generator;

uses(RefreshDatabase::class);

test('GET /api/v1/settings/roles is served only by the IAM controller', function (): void {
    $matches = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => $route->uri() === 'api/v1/settings/roles' && in_array('GET', $route->methods(), true));

    expect($matches)->toHaveCount(1);
    expect($matches->first()->getActionName())->toBe(IdentityRoleController::class.'@index');
    expect($matches->first()->gatherMiddleware())->toContain('can:settings.roles.view');
});

test('no two routes share the same method and uri', function (): void {
    $seen = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $seen[$method.' '.$route->uri()][] = $route->getActionName();
        }
    }

    $duplicates = array_filter($seen, static fn (array $actions): bool => count($actions) > 1);

    expect(array_map(static fn (array $a): string => implode(', ', $a), $duplicates))->toBe([]);
});

test('named routes are unique so the route cache can be built', function (): void {
    $names = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        if ($route->getName() !== null) {
            $names[$route->getName()][] = $route->uri();
        }
    }

    $duplicates = array_filter($names, static fn (array $uris): bool => count($uris) > 1);

    expect(array_map(static fn (array $u): string => implode(', ', $u), $duplicates))->toBe([]);
});

test('the legacy access roles live under settings/access and keep their own names', function (): void {
    expect(Route::has('api.v1.iam.roles.index'))->toBeTrue();
    expect(Route::getRoutes()->getByName('api.v1.iam.roles.index')->uri())->toBe('api/v1/settings/roles');

    $legacy = collect(Route::getRoutes()->getRoutes())->first(fn ($route): bool => $route->uri() === 'api/v1/settings/access/roles' && in_array('GET', $route->methods(), true));
    expect($legacy)->not->toBeNull();
    expect($legacy->getName())->not->toBe('api.v1.iam.roles.index');
});

test('the generated OpenAPI document lists GET /api/v1/settings/roles once', function (): void {
    $scan = new Generator;
    $api = $scan->generate([base_path('app'), base_path('modules'), base_path('packages')]);
    $operations = collect($api->paths)->filter(fn ($path): bool => $path->path === '/api/v1/settings/roles');

    expect($operations)->toHaveCount(1);
    expect($operations->first()->get->operationId)->toBe('listIdentityRoles');
});
