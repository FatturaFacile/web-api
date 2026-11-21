<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Domain\ValueObjects;

use Modules\AiCognitive\Domain\Enums\AiProviderType;

/**
 * The resolved settings of one provider of one tenant: defaults of the config merged with the tenant's choice and its
 * decrypted credentials. It exists only in memory while a call runs.
 */
final readonly class ProviderSettings
{
    /**
     * @param  array<string, mixed>  $extra  provider specific values (Azure: deployment, api_version)
     */
    public function __construct(
        public AiProviderType $provider,
        public ?string $endpoint,
        public ?string $model,
        public ?string $lightModel,
        public ?string $embeddingModel,
        public ?string $apiKey,
        public array $extra = [],
    ) {}

    public function modelFor(bool $light): ?string
    {
        return $light ? ($this->lightModel ?? $this->model) : $this->model;
    }
}
