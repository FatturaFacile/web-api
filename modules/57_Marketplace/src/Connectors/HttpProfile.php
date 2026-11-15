<?php

declare(strict_types=1);

namespace Modules\Marketplace\Connectors;

/**
 * How a {@see ProfileConnector} talks to the API of an external system: where it lives, how it authenticates, which
 * address answers a health check and which addresses list the resources.
 *
 * `baseUrl` may contain `{setting}` placeholders filled from the settings of the installation; `environments` maps the
 * value of the `environment` setting to a base URL and wins over `baseUrl`. `auth` is one of
 * `['type' => 'bearer', 'credential' => key]`, `['type' => 'header', 'name' => header, 'credential' => key]`,
 * `['type' => 'basic', 'user' => credentialKey, 'password' => credentialKey]` or `['type' => 'none']`.
 */
final readonly class HttpProfile
{
    /**
     * @param  array<string, string>  $environments
     * @param  array<string, string>  $auth
     * @param  array<string, array{path: string, collection: string|null}>  $resourcePaths
     */
    public function __construct(
        public string $baseUrl,
        public array $auth,
        public string $healthPath,
        public array $resourcePaths,
        public array $environments = [],
    ) {}
}
