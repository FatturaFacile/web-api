<?php

declare(strict_types=1);

namespace Modules\DataResidency\Application\Commands;

use Modules\DataResidency\Domain\Enums\KeyManagementMode;
use Modules\DataResidency\Domain\Enums\KmsProviderType;

final readonly class ConfigureByokOrHyokEnvelopeCommand
{
    /**
     * @param  array<string, mixed>  $credentials  Provider credentials; stored encrypted, never returned
     * @param  bool  $dryRun  Only test the handshake (wrap and unwrap of a throw-away key); persist nothing
     */
    public function __construct(
        public string $tenantId,
        public ?string $legalEntityId,
        public string $actorId,
        public KeyManagementMode $mode,
        public KmsProviderType $provider,
        public string $externalKeyUri,
        public ?string $kmipEndpoint,
        public array $credentials,
        public int $dekCacheTtlSeconds = 300,
        public int $rotationIntervalDays = 90,
        public bool $dryRun = false,
    ) {}
}
