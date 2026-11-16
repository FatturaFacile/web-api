<?php

declare(strict_types=1);

namespace Modules\Marketplace\Support;

use InvalidArgumentException;
use Modules\Marketplace\Contracts\Connector;

/**
 * The catalog of connectors. The built-in definitions are registered by the service provider of the module; any other
 * module or package registers its own {@see Connector} the same way. A key is registered once.
 */
final class ConnectorRegistry
{
    /**
     * @var array<string, Connector>
     */
    private array $connectors = [];

    /**
     * @throws InvalidArgumentException when the key is already taken
     */
    public function register(Connector $connector): void
    {
        $key = $connector->definition()->key;

        if (isset($this->connectors[$key])) {
            throw new InvalidArgumentException("The connector [{$key}] is already registered.");
        }

        $this->connectors[$key] = $connector;
    }

    public function find(string $key): ?Connector
    {
        return $this->connectors[$key] ?? null;
    }

    /**
     * @return array<string, Connector>
     */
    public function all(): array
    {
        ksort($this->connectors);

        return $this->connectors;
    }

    /**
     * The catalog entries, optionally of one category.
     *
     * @return list<ConnectorDefinition>
     */
    public function definitions(?string $category = null): array
    {
        $definitions = array_map(static fn (Connector $connector): ConnectorDefinition => $connector->definition(), array_values($this->all()));

        return array_values(array_filter($definitions, static fn (ConnectorDefinition $definition): bool => $category === null || $definition->category === $category));
    }
}
