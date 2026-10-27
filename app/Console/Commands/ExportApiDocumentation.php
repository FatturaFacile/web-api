<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

/**
 * Exports the API documentation of the application: the OpenAPI 3.1 specification (JSON and YAML) and a Postman
 * collection v2.1, both generated from the real routes through Scramble and completed with the security schemes
 * (bearer token and tenant header), the release version and the module tags.
 *
 * Run it with `php -d memory_limit=2G artisan docs:export` (the whole API is analysed). It is a development tool: it
 * needs the `symfony/yaml` package, which is available with the development dependencies.
 */
class ExportApiDocumentation extends Command
{
    protected $signature = 'docs:export {--path=docs/api : Directory that receives openapi.json, openapi.yaml and postman_collection.json}';

    protected $description = 'Export the OpenAPI 3.1 specification and the Postman collection of the API';

    private const METHODS = ['get', 'post', 'put', 'patch', 'delete'];

    /**
     * Path prefixes whose operations are public (no bearer token and no tenant header).
     *
     * @var list<string>
     */
    private const PUBLIC_PATHS = ['/v1/auth/login', '/v1/webhooks/', '/documentation'];

    /**
     * Postman folder of each API module; the other modules get a folder named after them.
     *
     * @var array<string, string>
     */
    private const array FOLDERS = ['auth' => 'auth', 'sales' => 'invoicing', 'purchases' => 'invoicing', 'banking' => 'banking', 'accounting' => 'accounting', 'system' => 'backups'];

    /**
     * Operations whose response carries a token or a tenant that the Postman session must remember.
     *
     * @var list<string>
     */
    private const array SESSION_PATHS = ['/v1/auth/login', '/v1/auth/refresh', '/v1/auth/switch-tenant'];

    public function handle(): int
    {
        if (! class_exists(Yaml::class)) {
            $this->error(__('docs.export.yaml_missing'));

            return self::FAILURE;
        }

        ini_set('memory_limit', '2G');

        $directory = base_path((string) $this->option('path'));
        File::ensureDirectoryExists($directory);

        $raw = (string) tempnam(sys_get_temp_dir(), 'openapi-');

        if ($this->callSilent('scramble:export', ['--path' => $raw]) !== self::SUCCESS || (int) filesize($raw) === 0) {
            @unlink($raw);
            $this->error(__('docs.export.generation_failed'));

            return self::FAILURE;
        }

        $spec = json_decode((string) file_get_contents($raw), false, 512, JSON_THROW_ON_ERROR);
        @unlink($raw);

        $this->addMetadata($spec);

        File::put("{$directory}/openapi.json", json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
        File::put("{$directory}/openapi.yaml", Yaml::dump($spec, 40, 2, Yaml::DUMP_OBJECT_AS_MAP | Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE));
        File::put("{$directory}/postman_collection.json", json_decode((string) json_encode($spec), true)
                |> $this(...)
                |> (fn ($x) => json_encode($x, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))."\n");

        $this->info(__('docs.export.done', ['paths' => count((array) $spec->paths), 'directory' => $this->option('path')]));

        return self::SUCCESS;
    }

    /**
     * Add what Scramble cannot know: title, version, servers, security schemes, public operations and module tags.
     */
    private function addMetadata(object $spec): void
    {
        $spec->info = (object) [
            'title' => __('docs.info.title'),
            'summary' => __('docs.info.summary'),
            'description' => __('docs.info.description'),
            'version' => (string) config('release.version'),
            'contact' => (object) ['name' => __('docs.info.contact')],
            'license' => (object) ['name' => 'Proprietary', 'identifier' => 'LicenseRef-Proprietary'],
        ];
        $spec->servers = [(object) ['url' => rtrim((string) config('app.url'), '/').'/api', 'description' => __('docs.servers.default')]];

        $spec->components ??= (object) [];
        $spec->components->securitySchemes = (object) [
            'BearerAuth' => (object) ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT', 'description' => __('docs.security.bearer')],
            'TenantHeader' => (object) ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Tenant-ID', 'description' => __('docs.security.tenant')],
        ];
        $spec->security = [(object) ['BearerAuth' => [], 'TenantHeader' => []]];

        $this->completeArrays($spec);

        $tags = [];

        foreach ($spec->paths as $path => $item) {
            foreach ($item as $method => $operation) {
                if (! in_array($method, self::METHODS, true)) {
                    continue;
                }

                if ($this->isPublic((string) $path)) {
                    $operation->security = [];
                }

                foreach ($operation->tags ?? [] as $tag) {
                    $tags[$tag] = true;
                }
            }
        }

        ksort($tags);
        $spec->tags = array_map(fn (string $tag): object => (object) ['name' => $tag, 'description' => __('docs.tags.default', ['tag' => $tag])], array_keys($tags));
    }

    /**
     * Every    ype: array schema needs an items keyword: where the analysis found none, any item is accepted.
     */
    private function completeArrays(mixed $node): void
    {
        if (is_object($node)) {
            $type = $node->type ?? null;

            if (($type === 'array' || (is_array($type) && in_array('array', $type, true))) && ! isset($node->items)) {
                $node->items = new \stdClass;
            }
        }

        if (is_object($node) || is_array($node)) {
            foreach ($node as $child) {
                $this->completeArrays($child);
            }
        }
    }

    private function isPublic(string $path): bool
    {
        foreach (self::PUBLIC_PATHS as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The Postman collection v2.1: one folder per module, the session variables and the scripts that fill them.
     *
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>
     */
    private function collection(array $doc): array
    {
        $folders = [];

        foreach ($doc['paths'] as $path => $item) {
            $module = explode('/', trim((string) $path, '/'))[1] ?? 'general';
            $folder = isset(self::FOLDERS[$module]) ? __('docs.postman.folders.'.self::FOLDERS[$module]) : Str::headline($module);

            foreach (self::METHODS as $method) {
                if (isset($item[$method])) {
                    $folders[$folder][] = $this->request((string) $path, $method, $item[$method], $doc);
                }
            }
        }

        $ordered = array_map(fn (string $key): string => __('docs.postman.folders.'.$key), ['auth', 'invoicing', 'banking', 'accounting', 'backups']);
        $others = array_diff(array_keys($folders), $ordered);
        sort($others);

        return [
            'info' => [
                '_postman_id' => (string) Str::uuid(),
                'name' => __('docs.info.title'),
                'description' => __('docs.postman.description'),
                'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
            ],
            'auth' => ['type' => 'bearer', 'bearer' => [['key' => 'token', 'value' => '{{authToken}}', 'type' => 'string']]],
            'variable' => [
                ['key' => 'baseUrl', 'value' => rtrim((string) config('app.url'), '/'), 'type' => 'string'],
                ['key' => 'tenantId', 'value' => '', 'type' => 'string'],
                ['key' => 'authToken', 'value' => '', 'type' => 'string'],
            ],
            'item' => array_merge($ordered, $others)
                    |> (fn ($x) => array_filter($x, fn (string $name): bool => isset($folders[$name])))
                    |> array_values(...)
                    |> (fn ($x) => array_map(fn (string $name): array => ['name' => $name, 'item' => $folders[$name]], $x))
                    |> array_values(...),
        ];
    }

    /**
     * @param  array<string, mixed>  $operation
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>
     */
    private function request(string $path, string $method, array $operation, array $doc): array
    {
        $public = $this->isPublic($path);
        $segments = trim($path, '/')
                |> (fn ($x) => explode('/', $x))
                |> (fn ($x) => array_map(fn (string $segment): string => preg_replace('/^\{(\w+)\}$/', ':$1', $segment), $x));
        $query = [];
        $variables = [];

        foreach ($operation['parameters'] ?? [] as $parameter) {
            if (($parameter['in'] ?? null) === 'query') {
                $query[] = ['key' => $parameter['name'], 'value' => '', 'disabled' => ! ($parameter['required'] ?? false), 'description' => (string) ($parameter['description'] ?? '')];
            }
        }

        foreach ($segments as $segment) {
            if (str_starts_with($segment, ':')) {
                $variables[] = ['key' => substr($segment, 1), 'value' => '', 'description' => ''];
            }
        }

        $required = array_filter($query, fn (array $parameter): bool => ! $parameter['disabled']);
        $raw = '{{baseUrl}}/api/'.implode('/', $segments).($required === [] ? '' : '?'.implode('&', array_map(fn (array $parameter): string => $parameter['key'].'=', $required)));

        $request = [
            'method' => strtoupper($method),
            'header' => array_values(array_filter([
                ['key' => 'Accept', 'value' => 'application/json'],
                ! $public ? ['key' => 'X-Tenant-ID', 'value' => '{{tenantId}}'] : null,
            ])),
            'url' => ['raw' => $raw, 'host' => ['{{baseUrl}}'], 'path' => ['api', ...$segments], 'query' => $query, 'variable' => $variables],
            'description' => (string) ($operation['description'] ?? $operation['summary'] ?? ''),
        ];

        if ($public) {
            $request['auth'] = ['type' => 'noauth'];
        }

        $body = $this->body($operation['requestBody']['content'] ?? [], $doc);

        if ($body !== null) {
            $request['body'] = $body;

            if ($body['mode'] === 'raw') {
                $request['header'][] = ['key' => 'Content-Type', 'value' => 'application/json'];
            }
        }

        $item = ['name' => (string) ($operation['summary'] ?? strtoupper($method).' /'.implode('/', $segments)), 'request' => $request];

        if (in_array($path, self::SESSION_PATHS, true)) {
            $item['event'] = [['listen' => 'test', 'script' => ['type' => 'text/javascript', 'exec' => $this->sessionScript()]]];
        } elseif ($path === '/v1/auth/logout') {
            $item['event'] = [['listen' => 'test', 'script' => ['type' => 'text/javascript', 'exec' => ['if (pm.response.code === 200) {', "    pm.collectionVariables.set('authToken', '');", '}']]]];
        }

        return $item;
    }

    /**
     * Post-response script: remember the bearer token and the active tenant in the collection session.
     *
     * @return list<string>
     */
    private function sessionScript(): array
    {
        return [
            'const response = pm.response.json();',
            'if (pm.response.code === 200 && response.data) {',
            "    if (response.data.token) { pm.collectionVariables.set('authToken', response.data.token); }",
            "    if (response.data.active_tenant) { pm.collectionVariables.set('tenantId', response.data.active_tenant.id); }",
            '}',
        ];
    }

    /**
     * @param  array<string, mixed>  $content
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>|null
     */
    private function body(array $content, array $doc): ?array
    {
        if (isset($content['application/json']['schema'])) {
            $example = $this->example($content['application/json']['schema'], $doc);

            return ['mode' => 'raw', 'raw' => json_encode($example === [] ? new \stdClass : $example, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'options' => ['raw' => ['language' => 'json']]];
        }

        if (isset($content['multipart/form-data']['schema'])) {
            $schema = $this->resolve($content['multipart/form-data']['schema'], $doc);
            $fields = [];

            foreach ($schema['properties'] ?? [] as $name => $property) {
                $property = $this->resolve($property, $doc);
                $fields[] = ($property['format'] ?? null) === 'binary'
                    ? ['key' => $name, 'type' => 'file', 'src' => []]
                    : ['key' => $name, 'type' => 'text', 'value' => (string) ($this->example($property, $doc) ?? '')];
            }

            return ['mode' => 'formdata', 'formdata' => $fields];
        }

        return null;
    }

    /**
     * Follow a `$ref` to the schema it names.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>
     */
    private function resolve(array $schema, array $doc): array
    {
        while (isset($schema['$ref'])) {
            $schema = $doc['components']['schemas'][basename((string) $schema['$ref'])] ?? [];
        }

        return $schema;
    }

    /**
     * A sample value for a schema, for the request bodies of the Postman collection.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $doc
     */
    private function example(array $schema, array $doc, int $depth = 0): mixed
    {
        $schema = $this->resolve($schema, $doc);

        if (array_key_exists('example', $schema)) {
            return $schema['example'];
        }

        if (isset($schema['enum'])) {
            return $schema['enum'][0];
        }

        foreach (['allOf', 'oneOf', 'anyOf'] as $combination) {
            if (isset($schema[$combination])) {
                $parts = $combination === 'allOf' ? $schema[$combination] : [$schema[$combination][0]];
                $merged = [];

                foreach ($parts as $part) {
                    $value = $this->example($part, $doc, $depth + 1);
                    $merged = is_array($value) && $combination === 'allOf' ? array_merge($merged, $value) : $value;
                }

                return $merged;
            }
        }

        $type = $schema['type'] ?? (isset($schema['properties']) ? 'object' : 'string');
        $type = is_array($type) ? (array_values(array_diff($type, ['null']))[0] ?? 'string') : $type;

        return match ($type) {
            'object' => $depth > 3 ? new \stdClass : (array) array_map(fn (array $property): mixed => $this->example($property, $doc, $depth + 1), $schema['properties'] ?? []),
            'array' => $depth > 3 ? [] : [$this->example($schema['items'] ?? [], $doc, $depth + 1)],
            'integer' => 1,
            'number' => 1.5,
            'boolean' => false,
            default => match ($schema['format'] ?? null) {
                'uuid' => '00000000-0000-0000-0000-000000000000',
                'email' => 'user@example.com',
                'date' => '2026-01-31',
                'date-time' => '2026-01-31T12:00:00+00:00',
                'uri' => 'https://example.com',
                default => 'string',
            },
        };
    }
}
