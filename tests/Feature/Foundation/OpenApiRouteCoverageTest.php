<?php

declare(strict_types=1);

use OpenApi\Attributes\Delete;
use OpenApi\Attributes\Get;
use OpenApi\Attributes\Patch;
use OpenApi\Attributes\Post;
use OpenApi\Attributes\Put;

/**
 * Every API route of the application and of every module declares its OpenAPI operation, with the same method and path:
 * on the controller method (custom subclasses of the swagger-php attributes included) or in a class of an OpenApi
 * folder. A PATCH route that shares its URI with a documented PUT (resource routes register both) counts as documented.
 */
test('every API route of every module declares its OpenAPI operation', function (): void {
    $attributeOf = ['GET' => Get::class, 'POST' => Post::class, 'PUT' => Put::class, 'PATCH' => Patch::class, 'DELETE' => Delete::class];
    $normalised = static fn (string $path): string => (string) preg_replace('/\{[^}]+\}/', '{}', '/'.trim($path, '/'));
    $declared = [];

    $files = array_merge(glob(base_path('app/OpenApi/*.php')) ?: [], glob(base_path('app/OpenApi/*/*.php')) ?: [], glob(base_path('modules/*/src/OpenApi/*.php')) ?: []);

    foreach ($files as $file) {
        $source = (string) file_get_contents($file);

        if (preg_match('/namespace\s+([^;]+);/', $source, $namespace) !== 1 || preg_match('/^(?:final\s+|abstract\s+)?class\s+(\w+)/m', $source, $class) !== 1) {
            continue;
        }

        foreach ($attributeOf as $method => $attribute) {
            foreach ((new ReflectionClass($namespace[1].'\\'.$class[1]))->getAttributes($attribute, ReflectionAttribute::IS_INSTANCEOF) as $operation) {
                $declared[$method.' '.$normalised((string) $operation->newInstance()->path)] = true;
            }
        }
    }

    $checked = 0;
    $missing = [];
    $modules = [];

    foreach (app('router')->getRoutes() as $route) {
        $action = $route->getActionName();

        if (! str_starts_with($route->uri(), 'api/') || ! (str_starts_with($action, 'App\\') || str_starts_with($action, 'Modules\\'))) {
            continue;
        }

        [$controller, $method] = str_contains($action, '@') ? explode('@', $action) : [$action, '__invoke'];
        $path = $normalised($route->uri());
        $methods = array_values(array_diff($route->methods(), ['HEAD']));

        if (str_starts_with($action, 'Modules\\')) {
            $modules[explode('\\', $action)[1]] = true;
        }

        foreach ($methods as $verb) {
            $checked++;
            $lookup = $verb === 'PATCH' && in_array('PUT', $methods, true) ? 'PUT' : $verb;
            $documented = isset($declared[$lookup.' '.$path]);

            if (! $documented && method_exists($controller, $method)) {
                foreach ((new ReflectionMethod($controller, $method))->getAttributes($attributeOf[$lookup], ReflectionAttribute::IS_INSTANCEOF) as $operation) {
                    $documented = $documented || $normalised((string) $operation->newInstance()->path) === $path;
                }
            }

            if (! $documented) {
                $missing[] = "{$verb} {$path} ({$action})";
            }
        }
    }

    $moduleFolders = array_map(static fn (string $folder): string => basename($folder), glob(base_path('modules/*'), GLOB_ONLYDIR) ?: []);

    expect($missing)->toBe([])
        ->and($checked)->toBeGreaterThan(1000)
        ->and(count($modules))->toBeGreaterThanOrEqual(30)
        ->and(array_keys($modules))->toContain('Visitors', 'Hospitality', 'Associations', 'Customs', 'Payroll')
        ->and(count($moduleFolders))->toBeGreaterThanOrEqual(count($modules));
});
