<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Services;

use Illuminate\Contracts\Container\Container;
use Modules\ElectronicSignature\Contracts\ElectronicSignatureProvider;
use Modules\ElectronicSignature\Enums\Jurisdiction;
use Modules\ElectronicSignature\Enums\ProviderType;
use Modules\ElectronicSignature\Enums\SignatureLevel;
use Modules\ElectronicSignature\Exceptions\SignatureException;
use Modules\ElectronicSignature\Gateways\InfoCertSignatureProvider;
use Modules\ElectronicSignature\Gateways\NamirialSignatureProvider;
use Modules\ElectronicSignature\Gateways\NativeSignatureProvider;
use Modules\ElectronicSignature\Gateways\SwisscomSignatureProvider;
use Modules\ElectronicSignature\Models\SignatureProviderConfig;

/**
 * The provider adapters, and the choice of the provider configuration of a company for a signature request.
 */
final class ProviderRegistry
{
    public function __construct(private readonly Container $container) {}

    public function provider(ProviderType $type): ElectronicSignatureProvider
    {
        return $this->container->make(match ($type) {
            ProviderType::Native => NativeSignatureProvider::class,
            ProviderType::Swisscom => SwisscomSignatureProvider::class,
            ProviderType::InfoCert => InfoCertSignatureProvider::class,
            ProviderType::Namirial => NamirialSignatureProvider::class,
        });
    }

    /**
     * The tiers each provider can issue, for the wizard.
     *
     * @return array<string, array<string, list<string>>> provider => jurisdiction => levels
     */
    public function capabilities(): array
    {
        $capabilities = [];

        foreach (ProviderType::cases() as $type) {
            $provider = $this->provider($type);

            foreach (Jurisdiction::cases() as $jurisdiction) {
                $capabilities[$type->value][$jurisdiction->value] = array_values(array_map(
                    static fn (SignatureLevel $level): string => $level->value,
                    array_filter(SignatureLevel::cases(), static fn (SignatureLevel $level): bool => $provider->supports($level, $jurisdiction)),
                ));
            }
        }

        return $capabilities;
    }

    /**
     * The configuration to use: the one chosen, or the default of the company that can issue the tier, or the built-in
     * engine (which exists for every company) when the tier allows it.
     *
     * @throws SignatureException `no_provider_available` when nothing can issue the tier, `provider_cannot_issue_level` for a chosen one that cannot
     */
    public function configFor(?string $configId, SignatureLevel $level, Jurisdiction $jurisdiction): SignatureProviderConfig
    {
        if ($configId !== null) {
            $config = SignatureProviderConfig::query()->where('is_active', true)->find($configId) ?? throw new SignatureException('provider_not_found', 404);

            if (! $this->canIssue($config, $level, $jurisdiction)) {
                throw new SignatureException('provider_cannot_issue_level');
            }

            return $config;
        }

        $candidates = SignatureProviderConfig::query()->where('is_active', true)->orderByDesc('is_default')->orderBy('created_at')->get()
            ->filter(fn (SignatureProviderConfig $config): bool => $this->canIssue($config, $level, $jurisdiction));

        if ($candidates->isNotEmpty()) {
            return $candidates->first();
        }

        if ($this->provider(ProviderType::Native)->supports($level, $jurisdiction)) {
            return $this->nativeConfig();
        }

        throw new SignatureException('no_provider_available');
    }

    public function canIssue(SignatureProviderConfig $config, SignatureLevel $level, Jurisdiction $jurisdiction): bool
    {
        return $config->jurisdiction->covers($jurisdiction) && $this->provider($config->provider)->supports($level, $jurisdiction);
    }

    /**
     * The built-in engine of the company, created on first use: it needs no credentials and seals with a key the platform manages.
     */
    public function nativeConfig(): SignatureProviderConfig
    {
        $existing = SignatureProviderConfig::query()->where('provider', ProviderType::Native->value)->where('is_active', true)->orderByDesc('is_default')->first();

        if ($existing !== null) {
            return $existing;
        }

        return SignatureProviderConfig::query()->create([
            'provider' => ProviderType::Native,
            'jurisdiction' => 'BOTH',
            'name' => 'FatturaFacile',
            // SMS codes of the advanced signature are only sent once the company switches the sandbox off.
            'is_sandbox' => ! app()->isProduction(),
            'is_active' => true,
            'is_default' => false,
        ]);
    }
}
