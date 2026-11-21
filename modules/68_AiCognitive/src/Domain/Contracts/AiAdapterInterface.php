<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Domain\Contracts;

use Modules\AiCognitive\Domain\Enums\AiProviderType;
use Modules\AiCognitive\Domain\Exceptions\AiProviderUnavailableException;
use Modules\AiCognitive\Domain\ValueObjects\AiCompletionResult;
use Modules\AiCognitive\Domain\ValueObjects\ProviderSettings;

/**
 * The wire protocol of one model provider. An adapter holds no tenant state: the settings come with every call.
 */
interface AiAdapterInterface
{
    public function provider(): AiProviderType;

    /**
     * @throws AiProviderUnavailableException when the provider cannot answer
     */
    public function complete(ProviderSettings $settings, string $system, string $prompt, int $maxTokens, float $temperature, bool $expectJson, bool $light): AiCompletionResult;

    /**
     * @return list<float>
     *
     * @throws AiProviderUnavailableException
     */
    public function embed(ProviderSettings $settings, string $text): array;
}
