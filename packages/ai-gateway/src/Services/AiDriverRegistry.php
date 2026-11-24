<?php

declare(strict_types=1);

namespace Enterprise\AiGateway\Services;

use Enterprise\AiGateway\Contracts\AiDriverInterface;
use InvalidArgumentException;

final class AiDriverRegistry
{
    /**
     * @var array<string, AiDriverInterface>
     */
    private array $drivers = [];

    private string $defaultDriver = 'ollama';

    private string $fallbackDriver = 'ollama';

    public function register(string $name, AiDriverInterface $driver): void
    {
        $this->drivers[strtolower($name)] = $driver;
    }

    public function get(string $name): AiDriverInterface
    {
        $key = strtolower($name);
        if (! isset($this->drivers[$key])) {
            throw new InvalidArgumentException("AI Driver [{$name}] is not registered.");
        }

        return $this->drivers[$key];
    }

    public function has(string $name): bool
    {
        return isset($this->drivers[strtolower($name)]);
    }

    public function getDefaultDriver(): AiDriverInterface
    {
        return $this->get($this->defaultDriver);
    }

    public function getFallbackDriver(): AiDriverInterface
    {
        return $this->get($this->fallbackDriver);
    }

    public function setDefaultDriver(string $name): void
    {
        $this->defaultDriver = $name;
    }

    public function setFallbackDriver(string $name): void
    {
        $this->fallbackDriver = $name;
    }
}
