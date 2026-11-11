<?php

declare(strict_types=1);

/**
 * The tracked OpenAPI artefacts must describe the real authentication: the session cookie first, the Bearer token only
 * as the alternative of the optional token mode, and nothing on the public operations. Regenerate them with
 * `php -d memory_limit=512M artisan l5-swagger:generate` and `php artisan docs:export` when this test fails.
 *
 * @return list<array{0: string, 1: string, 2: array<string, mixed>}> path, method, operation
 */
function openApiOperations(string $file): array
{
    $spec = json_decode((string) file_get_contents(base_path($file)), true, 512, JSON_THROW_ON_ERROR);
    $operations = [];

    foreach ($spec['paths'] as $path => $item) {
        foreach (['get', 'post', 'put', 'patch', 'delete'] as $method) {
            if (isset($item[$method])) {
                $operations[] = [$path, $method, $item[$method]];
            }
        }
    }

    return $operations;
}

/**
 * @param  array<string, mixed>  $operation
 * @param  array<string, mixed>  $spec
 * @return list<string>
 */
function securitySchemesOf(array $operation, string $file): array
{
    $spec = json_decode((string) file_get_contents(base_path($file)), true, 512, JSON_THROW_ON_ERROR);
    $security = $operation['security'] ?? $spec['security'] ?? [];

    return array_values(array_unique(array_merge(...array_map(fn (array $requirement): array => array_keys($requirement), $security ?: [[]]))));
}

test('every secured operation of the swagger document lists cookieAuth first and bearerAuth as the token mode alternative', function (): void {
    $missing = [];

    foreach (openApiOperations('storage/api-docs/api-docs.json') as [$path, $method, $operation]) {
        $schemes = securitySchemesOf($operation, 'storage/api-docs/api-docs.json');

        // Public operations list nothing; SCIM has its own provisioning token.
        if (! in_array('bearerAuth', $schemes, true) && ! in_array('cookieAuth', $schemes, true)) {
            continue;
        }

        $first = array_key_first($operation['security'][0] ?? []);

        if (! in_array('cookieAuth', $schemes, true) || $first !== 'cookieAuth') {
            $missing[] = strtoupper($method).' '.$path;
        }
    }

    expect($missing)->toBe([], 'Operations without cookieAuth as the primary scheme: '.implode(', ', array_slice($missing, 0, 15)));
});

test('the exported specification lists CookieAuth for every secured operation and none for the public ones', function (): void {
    $spec = json_decode((string) file_get_contents(base_path('docs/api/openapi.json')), true, 512, JSON_THROW_ON_ERROR);

    expect($spec['components']['securitySchemes'])->toHaveKeys(['CookieAuth', 'BearerAuth', 'TenantHeader'])
        ->and($spec['components']['securitySchemes']['CookieAuth'])->toMatchArray(['type' => 'apiKey', 'in' => 'cookie'])
        ->and($spec['info']['description'])->toContain('/sanctum/csrf-cookie')->toContain('X-XSRF-TOKEN')->toContain('token_mode');

    $missing = [];

    foreach (openApiOperations('docs/api/openapi.json') as [$path, $method, $operation]) {
        $schemes = securitySchemesOf($operation, 'docs/api/openapi.json');

        if ($schemes !== [] && ! in_array('CookieAuth', $schemes, true)) {
            $missing[] = strtoupper($method).' '.$path;
        }
    }

    expect($missing)->toBe([], 'Secured operations without CookieAuth: '.implode(', ', array_slice($missing, 0, 15)));
});

test('the login operation documents token_mode and the csrf cookie step is documented', function (): void {
    $swagger = json_decode((string) file_get_contents(base_path('storage/api-docs/api-docs.json')), true, 512, JSON_THROW_ON_ERROR);

    expect($swagger['paths'])->toHaveKey('/sanctum/csrf-cookie')
        ->and($swagger['paths']['/api/v1/auth/login']['post']['requestBody']['content']['application/json']['schema']['properties'])->toHaveKey('token_mode')
        ->and($swagger['components']['securitySchemes'])->toHaveKey('cookieAuth')
        ->and($swagger['paths']['/api/v1/auth/login']['post']['security'] ?? [])->toBe([]);
});
