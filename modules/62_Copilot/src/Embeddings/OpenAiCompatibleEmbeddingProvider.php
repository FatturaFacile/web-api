<?php

declare(strict_types=1);

namespace Modules\Copilot\Embeddings;

use Illuminate\Support\Facades\Http;
use Modules\Copilot\Contracts\EmbeddingProviderInterface;
use Modules\Copilot\Llm\CopilotProviderException;

/**
 * Embeddings over the OpenAI wire format: OpenAI, a self-hosted vLLM server or Ollama (through its /v1 endpoint).
 */
final readonly class OpenAiCompatibleEmbeddingProvider implements EmbeddingProviderInterface
{
    public function __construct(
        private string $name,
        private string $baseUrl,
        private ?string $apiKey,
        private ?string $model,
        private bool $external,
        private bool $requiresKey = true,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function isExternal(): bool
    {
        return $this->external;
    }

    public function embed(string $text): array
    {
        if (($this->requiresKey && empty($this->apiKey)) || empty($this->model)) {
            throw CopilotProviderException::notConfigured($this->name);
        }

        $pending = Http::timeout(60)->acceptJson();

        if (! empty($this->apiKey)) {
            $pending = $pending->withToken($this->apiKey);
        }

        $response = $pending->post(rtrim($this->baseUrl, '/').'/embeddings', ['model' => $this->model, 'input' => $text]);

        if (! $response->successful()) {
            throw CopilotProviderException::failed($this->name, $response->status());
        }

        $vector = $response->json('data.0.embedding');

        if (! is_array($vector) || $vector === []) {
            throw CopilotProviderException::unreadable($this->name);
        }

        return array_map(floatval(...), array_values($vector));
    }
}
