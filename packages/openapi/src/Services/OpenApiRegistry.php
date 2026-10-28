<?php

declare(strict_types=1);

namespace Enterprise\OpenApi\Services;

final class OpenApiRegistry
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $endpoints = [];

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function registerEndpoint(string $route, array $metadata): void
    {
        $this->endpoints[$route] = $metadata;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getEndpoints(): array
    {
        return $this->endpoints;
    }
}
