<?php

declare(strict_types=1);

namespace Modules\Copilot\Embeddings;

use Illuminate\Support\Facades\Http;
use Modules\Copilot\Contracts\EmbeddingProviderInterface;
use Modules\Copilot\Llm\CopilotProviderException;

final readonly class GeminiEmbeddingProvider implements EmbeddingProviderInterface
{
    public function __construct(
        private ?string $apiKey,
        private string $baseUrl,
        private ?string $model,
    ) {}

    public function name(): string
    {
        return 'gemini';
    }

    public function isExternal(): bool
    {
        return true;
    }

    public function embed(string $text): array
    {
        if (empty($this->apiKey) || empty($this->model)) {
            throw CopilotProviderException::notConfigured($this->name());
        }

        $response = Http::timeout(60)
            ->withHeaders(['x-goog-api-key' => $this->apiKey])
            ->acceptJson()
            ->post(rtrim($this->baseUrl, '/')."/models/{$this->model}:embedContent", [
                'model' => 'models/'.$this->model,
                'content' => ['parts' => [['text' => $text]]],
            ]);

        if (! $response->successful()) {
            throw CopilotProviderException::failed($this->name(), $response->status());
        }

        $vector = $response->json('embedding.values');

        if (! is_array($vector) || $vector === []) {
            throw CopilotProviderException::unreadable($this->name());
        }

        return array_map(floatval(...), array_values($vector));
    }
}
