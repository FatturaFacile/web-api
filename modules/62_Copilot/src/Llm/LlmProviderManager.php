<?php

declare(strict_types=1);

namespace Modules\Copilot\Llm;

use Enterprise\AiGateway\Contracts\TenantAiConsentInterface;
use Enterprise\AiGateway\Exceptions\ExternalAiNotAllowedException;
use InvalidArgumentException;
use Modules\Copilot\Contracts\LlmProviderInterface;
use Modules\DataResidency\Infrastructure\Services\CrossBorderEgressGuard;

/**
 * Resolves the configured model provider for a tenant. A hosted provider is handed out only to a tenant that allowed
 * external AI providers; a tenant that did not never reaches it, so the data stays inside the installation.
 */
final class LlmProviderManager
{
    /**
     * @var array<string, LlmProviderInterface>
     */
    private array $providers = [];

    public function __construct(private readonly TenantAiConsentInterface $consent) {}

    public function register(string $name, LlmProviderInterface $provider): void
    {
        $this->providers[$name] = $provider;
    }

    /**
     * @throws ExternalAiNotAllowedException
     */
    public function forTenant(string $tenantId, ?string $driver = null): LlmProviderInterface
    {
        $driver ??= (string) config('copilot.driver', 'local');
        $provider = $this->resolve($driver);

        if ($provider->isExternal() && ! $this->consent->allowsExternalProviders($tenantId)) {
            throw ExternalAiNotAllowedException::forDriver($provider->name());
        }

        if ($provider->isExternal()) {
            // Art. 16 nLPD / Art. 44 GDPR: a hosted model is reached only if the residency policy allows its country.
            app(CrossBorderEgressGuard::class)->assertAllowed($tenantId, null, 'ai', (string) config("copilot.drivers.{$driver}.base_url"));
        }

        return $provider;
    }

    private function resolve(string $driver): LlmProviderInterface
    {
        if (isset($this->providers[$driver])) {
            return $this->providers[$driver];
        }

        /** @var array<string, mixed> $config */
        $config = (array) config("copilot.drivers.{$driver}", []);

        $provider = match ($driver) {
            'local' => new LocalLlmProvider,
            'openai' => new OpenAiCompatibleLlmProvider('openai', (string) $config['base_url'], $config['api_key'] ?? null, $config['model'] ?? null, external: true),
            'vllm' => new OpenAiCompatibleLlmProvider('vllm', (string) $config['base_url'], $config['api_key'] ?? null, $config['model'] ?? null, external: false, requiresKey: false),
            'ollama' => new OpenAiCompatibleLlmProvider('ollama', rtrim((string) $config['base_url'], '/').'/v1', null, $config['model'] ?? null, external: false, requiresKey: false),
            'anthropic' => new AnthropicLlmProvider($config['api_key'] ?? null, (string) $config['base_url'], $config['model'] ?? null),
            'gemini' => new GeminiLlmProvider($config['api_key'] ?? null, (string) $config['base_url'], $config['model'] ?? null),
            default => throw new InvalidArgumentException("Unknown Copilot driver [{$driver}]."),
        };

        return $this->providers[$driver] = $provider;
    }
}
