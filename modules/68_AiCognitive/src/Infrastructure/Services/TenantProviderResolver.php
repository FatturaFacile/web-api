<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Infrastructure\Services;

use Enterprise\AiGateway\Contracts\TenantAiConsentInterface;
use Modules\AiCognitive\Domain\Contracts\AiAdapterInterface;
use Modules\AiCognitive\Domain\Enums\AiProviderType;
use Modules\AiCognitive\Domain\Enums\DataClassification;
use Modules\AiCognitive\Domain\ValueObjects\ProviderSettings;
use Modules\AiCognitive\Infrastructure\Models\AiProviderConfig;

/**
 * The providers a tenant may use right now, with their settings resolved from the config defaults, the tenant row and its
 * decrypted credentials. A tenant without any configuration runs on the local Ollama of the installation (zero egress). A
 * hosted provider is offered only to a tenant that allowed external AI providers.
 */
final readonly class TenantProviderResolver
{
    /**
     * @param  iterable<AiAdapterInterface>  $adapters
     */
    public function __construct(
        private iterable $adapters,
        private TenantAiConsentInterface $consent,
    ) {}

    /**
     * @return list<ResolvedProvider>
     */
    public function candidates(string $tenantId): array
    {
        $configs = AiProviderConfig::query()->where('tenant_id', $tenantId)->where('is_enabled', true)->get();

        if ($configs->isEmpty()) {
            $local = $this->implicitLocal();

            return $local === null ? [] : [$local];
        }

        $external = $this->consent->allowsExternalProviders($tenantId);
        $resolved = [];

        foreach ($configs as $config) {
            if (! $config->provider->isLocal() && ! $external) {
                continue;
            }

            $adapter = $this->adapter($config->provider);

            if ($adapter === null) {
                continue;
            }

            $resolved[] = new ResolvedProvider($config->provider, $this->settings($config), $adapter, $config);
        }

        return $resolved;
    }

    public function adapter(AiProviderType $provider): ?AiAdapterInterface
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->provider() === $provider) {
                return $adapter;
            }
        }

        return null;
    }

    public function settings(AiProviderConfig $config): ProviderSettings
    {
        /** @var array<string, string|null> $defaults */
        $defaults = (array) config('ai_cognitive.defaults.'.$config->provider->value, []);

        return new ProviderSettings(
            $config->provider,
            $config->endpoint ?? $defaults['endpoint'] ?? null,
            $config->model ?? $defaults['model'] ?? null,
            $config->light_model ?? $defaults['light_model'] ?? null,
            $config->embedding_model ?? $defaults['embedding_model'] ?? null,
            $config->apiKey(),
            $config->extra(),
        );
    }

    private function implicitLocal(): ?ResolvedProvider
    {
        $adapter = $this->adapter(AiProviderType::Ollama);

        if ($adapter === null) {
            return null;
        }

        /** @var array<string, string|null> $defaults */
        $defaults = (array) config('ai_cognitive.defaults.ollama', []);
        $settings = new ProviderSettings(AiProviderType::Ollama, $defaults['endpoint'] ?? null, $defaults['model'] ?? null, $defaults['light_model'] ?? null, $defaults['embedding_model'] ?? null, null);

        return new ResolvedProvider(AiProviderType::Ollama, $settings, $adapter, null);
    }

    public static function classificationLimit(?AiProviderConfig $config, AiProviderType $provider): DataClassification
    {
        return $config->max_data_classification ?? ($provider->isLocal() ? DataClassification::ConfidentialPii : DataClassification::Internal);
    }
}
