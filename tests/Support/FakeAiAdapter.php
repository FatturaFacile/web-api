<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Modules\AiCognitive\Domain\Contracts\AiAdapterInterface;
use Modules\AiCognitive\Domain\Enums\AiProviderType;
use Modules\AiCognitive\Domain\Exceptions\AiProviderUnavailableException;
use Modules\AiCognitive\Domain\ValueObjects\AiCompletionResult;
use Modules\AiCognitive\Domain\ValueObjects\ProviderSettings;
use Modules\Copilot\Embeddings\LocalHashEmbeddingProvider;

/**
 * A provider adapter that answers from a closure (deterministic fixtures) and records what it was sent, so the tests can
 * check what a hosted provider would have seen. `fail` makes every call fail, to exercise retries and the circuit breaker.
 */
final class FakeAiAdapter implements AiAdapterInterface
{
    /**
     * @var list<array{system: string, prompt: string, light: bool, model: string|null}>
     */
    public array $calls = [];

    public int $embeddings = 0;

    public bool $fail = false;

    /**
     * @param  Closure(string, string): string|null  $answer  receives the system prompt and the prompt it was sent
     */
    public function __construct(private readonly AiProviderType $type, private ?Closure $answer = null) {}

    public function provider(): AiProviderType
    {
        return $this->type;
    }

    public function complete(ProviderSettings $settings, string $system, string $prompt, int $maxTokens, float $temperature, bool $expectJson, bool $light): AiCompletionResult
    {
        $this->calls[] = ['system' => $system, 'prompt' => $prompt, 'light' => $light, 'model' => $settings->modelFor($light)];

        if ($this->fail) {
            throw AiProviderUnavailableException::failed($this->type->value, 'down');
        }

        $content = $this->answer !== null ? ($this->answer)($system, $prompt) : '{}';

        return new AiCompletionResult($content, $this->type->value, (string) $settings->modelFor($light), 100, 50, 5);
    }

    public function embed(ProviderSettings $settings, string $text): array
    {
        $this->embeddings++;

        if ($this->fail) {
            throw AiProviderUnavailableException::failed($this->type->value, 'down');
        }

        return (new LocalHashEmbeddingProvider)->embed($text);
    }
}
