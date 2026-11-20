<?php

declare(strict_types=1);

namespace Modules\Copilot\Embeddings;

use Enterprise\AiGateway\Contracts\TenantAiConsentInterface;
use Enterprise\AiGateway\Exceptions\ExternalAiNotAllowedException;
use InvalidArgumentException;
use Modules\Copilot\Contracts\EmbeddingProviderInterface;

/**
 * Resolves the configured embedding provider for a tenant, with the same data-residency rule as the model provider.
 * Anthropic offers no embedding model, so it is not a valid embedding driver.
 */
final class EmbeddingProviderManager
{
    /**
     * @var array<string, EmbeddingProviderInterface>
     */
    private array $providers = [];

    public function __construct(private readonly TenantAiConsentInterface $consent) {}

    public function register(string $name, EmbeddingProviderInterface $provider): void
    {
        $this->providers[$name] = $provider;
    }

    /**
     * @throws ExternalAiNotAllowedException
     */
    public function forTenant(string $tenantId, ?string $driver = null): EmbeddingProviderInterface
    {
        $provider = $this->resolve($driver ?? (string) config('copilot.embedding_driver', 'local'));

        if ($provider->isExternal() && ! $this->consent->allowsExternalProviders($tenantId)) {
            throw ExternalAiNotAllowedException::forDriver($provider->name());
        }

        return $provider;
    }

    private function resolve(string $driver): EmbeddingProviderInterface
    {
        if (isset($this->providers[$driver])) {
            return $this->providers[$driver];
        }

        /** @var array<string, mixed> $config */
        $config = (array) config("copilot.drivers.{$driver}", []);

        $provider = match ($driver) {
            'local' => new LocalHashEmbeddingProvider,
            'openai' => new OpenAiCompatibleEmbeddingProvider('openai', (string) $config['base_url'], $config['api_key'] ?? null, $config['embedding_model'] ?? null, external: true),
            'vllm' => new OpenAiCompatibleEmbeddingProvider('vllm', (string) $config['base_url'], $config['api_key'] ?? null, $config['embedding_model'] ?? null, external: false, requiresKey: false),
            'ollama' => new OpenAiCompatibleEmbeddingProvider('ollama', rtrim((string) $config['base_url'], '/').'/v1', null, $config['embedding_model'] ?? null, external: false, requiresKey: false),
            'gemini' => new GeminiEmbeddingProvider($config['api_key'] ?? null, (string) $config['base_url'], $config['embedding_model'] ?? null),
            default => throw new InvalidArgumentException("Unknown Copilot embedding driver [{$driver}]."),
        };

        return $this->providers[$driver] = $provider;
    }
}
