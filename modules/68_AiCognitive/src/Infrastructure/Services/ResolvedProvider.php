<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Infrastructure\Services;

use Modules\AiCognitive\Domain\Contracts\AiAdapterInterface;
use Modules\AiCognitive\Domain\Enums\AiProviderType;
use Modules\AiCognitive\Domain\ValueObjects\ProviderSettings;
use Modules\AiCognitive\Infrastructure\Models\AiProviderConfig;

/**
 * A provider of a tenant ready to be called: its settings, its adapter and the tenant row it came from (null for the
 * implicit local provider of a tenant without configuration).
 */
final readonly class ResolvedProvider
{
    public function __construct(
        public AiProviderType $type,
        public ProviderSettings $settings,
        public AiAdapterInterface $adapter,
        public ?AiProviderConfig $config,
    ) {}

    public function piiMasking(): bool
    {
        return ! $this->type->isLocal() && ($this->config->pii_masking_enabled ?? true);
    }
}
