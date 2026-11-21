<?php

declare(strict_types=1);

namespace Modules\DataResidency\Infrastructure\Kms;

use Modules\DataResidency\Domain\Enums\KmsProviderType;

/**
 * Resolves the adapter of a provider type. Tests (and custom deployments) replace an adapter with extend().
 */
final class KmsProviderRegistry
{
    /** @var array<string, KmsProviderInterface> */
    private array $adapters = [];

    public function __construct()
    {
        foreach ([
            new SecurosysPrimusHsmAdapter, new AwsKmsAdapter, new AzureKeyVaultAdapter, new GoogleCloudEkmAdapter,
            new HashiCorpVaultTransitAdapter, new KmipGenericAdapter, new PlatformKmsAdapter,
        ] as $adapter) {
            $this->extend($adapter);
        }
    }

    public function extend(KmsProviderInterface $adapter): void
    {
        $this->adapters[$adapter->type()->value] = $adapter;
    }

    public function for(KmsProviderType $type): KmsProviderInterface
    {
        return $this->adapters[$type->value];
    }
}
