<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Infrastructure\Services;

use Enterprise\AiGateway\Contracts\TenantAiConsentInterface;
use Modules\OcrExtraction\Domain\Contracts\OcrVisionProviderInterface;
use Modules\OcrExtraction\Domain\Enums\OcrProviderType;
use Modules\OcrExtraction\Domain\Exceptions\OcrProviderException;
use Modules\OcrExtraction\Infrastructure\Models\TenantOcrConfiguration;

/**
 * Picks the engines of a tenant: its primary provider, then its fallback. The tenant is the only source of the choice and
 * of the settings, so tenants never share an engine configuration. A cloud engine is skipped unless the tenant consented
 * to external AI providers.
 */
final class OcrProviderRegistry
{
    /**
     * @param  iterable<OcrVisionProviderInterface>  $providers
     */
    public function __construct(private readonly iterable $providers, private readonly TenantAiConsentInterface $consent) {}

    public function provider(OcrProviderType $type): OcrVisionProviderInterface
    {
        foreach ($this->providers as $provider) {
            if ($provider->type() === $type) {
                return $provider;
            }
        }

        throw new OcrProviderException("No OCR engine is registered for '{$type->value}'.");
    }

    /**
     * Engines to try in order, each with the decrypted settings of the tenant.
     *
     * @return list<array{provider: OcrVisionProviderInterface, settings: array<string, mixed>}>
     *
     * @throws OcrProviderException when the tenant has no engine it is allowed to use
     */
    public function chainFor(string $tenantId): array
    {
        $configuration = TenantOcrConfiguration::query()->where('tenant_id', $tenantId)->first();
        $types = array_filter([
            $configuration->primary_provider ?? OcrProviderType::OllamaLocal,
            $configuration?->fallback_provider,
        ]);

        $allowsExternal = $this->consent->allowsExternalProviders($tenantId);
        $chain = [];

        foreach ($types as $type) {
            if ($type->isCloud() && ! $allowsExternal) {
                continue;
            }

            $chain[] = ['provider' => $this->provider($type), 'settings' => $configuration?->settingsFor($type) ?? []];
        }

        if ($chain === []) {
            throw new OcrProviderException('The tenant has not allowed external AI providers and has no local OCR engine configured.');
        }

        return $chain;
    }
}
