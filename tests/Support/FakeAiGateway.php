<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Modules\AiCognitive\Domain\Contracts\AiProviderGatewayInterface;
use Modules\AiCognitive\Domain\ValueObjects\AiCompletionRequest;
use Modules\AiCognitive\Domain\ValueObjects\AiCompletionResult;
use Modules\Copilot\Embeddings\LocalHashEmbeddingProvider;

/**
 * The gateway of the AI layer for the pipeline tests: answers with the JSON of a fixture and records every request.
 */
final class FakeAiGateway implements AiProviderGatewayInterface
{
    /**
     * @var list<AiCompletionRequest>
     */
    public array $requests = [];

    /**
     * @param  Closure(AiCompletionRequest): (array<string, mixed>|string)  $answer
     */
    public function __construct(private Closure $answer) {}

    public function complete(AiCompletionRequest $request): AiCompletionResult
    {
        $this->requests[] = $request;
        $answer = ($this->answer)($request);

        return new AiCompletionResult(is_array($answer) ? (string) json_encode($answer) : $answer, 'ollama', 'fake-model', 120, 60, 3);
    }

    public function embed(string $tenantId, string $text): array
    {
        return (new LocalHashEmbeddingProvider)->embed($text);
    }
}
