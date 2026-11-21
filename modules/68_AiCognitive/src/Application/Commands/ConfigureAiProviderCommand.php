<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Application\Commands;

use Modules\AiCognitive\Domain\Enums\AiProviderType;
use Modules\AiCognitive\Domain\Enums\DataClassification;

/**
 * Sets up one provider of a tenant. A null API key keeps the stored key; the key is never read back.
 */
final readonly class ConfigureAiProviderCommand
{
    /**
     * @param  array<string, mixed>  $extra  provider specific options (Azure: deployment, api_version, embedding_deployment)
     */
    public function __construct(
        public string $tenantId,
        public ?string $userId,
        public AiProviderType $provider,
        public bool $enabled = true,
        public bool $default = false,
        public ?string $label = null,
        public ?string $endpoint = null,
        public ?string $model = null,
        public ?string $lightModel = null,
        public ?string $embeddingModel = null,
        public ?string $apiKey = null,
        public array $extra = [],
        public DataClassification $maxDataClassification = DataClassification::Internal,
        public bool $piiMasking = true,
        public ?int $latencySlaMs = null,
        public ?int $monthlyTokenBudget = null,
        public ?int $rateLimitPerMinute = null,
    ) {}
}
