<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Infrastructure\Adapters;

use Illuminate\Support\Facades\Http;
use Modules\AiCognitive\Domain\Contracts\AiAdapterInterface;
use Modules\AiCognitive\Domain\Enums\AiProviderType;
use Modules\AiCognitive\Domain\Exceptions\AiProviderUnavailableException;
use Modules\AiCognitive\Domain\ValueObjects\AiCompletionResult;
use Modules\AiCognitive\Domain\ValueObjects\ProviderSettings;
use Throwable;

/**
 * Azure OpenAI on the tenant's own resource: the endpoint is the resource address, the model setting names the deployment
 * (the extra `deployment` and `embedding_deployment` override it) and the key goes in the `api-key` header.
 */
final class AzureOpenAiAdapter implements AiAdapterInterface
{
    private const string DefaultApiVersion = '2024-06-01';

    public function provider(): AiProviderType
    {
        return AiProviderType::AzureOpenAi;
    }

    public function complete(ProviderSettings $settings, string $system, string $prompt, int $maxTokens, float $temperature, bool $expectJson, bool $light): AiCompletionResult
    {
        $deployment = (string) ($settings->extra['deployment'] ?? $settings->modelFor($light));
        $started = hrtime(true);

        $body = [
            'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $prompt]],
            'temperature' => $temperature,
            'max_tokens' => $maxTokens,
        ];

        if ($expectJson) {
            $body['response_format'] = ['type' => 'json_object'];
        }

        $json = $this->post($settings, "deployments/{$deployment}/chat/completions", $body);
        $content = $json['choices'][0]['message']['content'] ?? null;

        if (! is_string($content)) {
            throw AiProviderUnavailableException::failed($this->provider()->value, 'unreadable answer');
        }

        return new AiCompletionResult(
            $content,
            $this->provider()->value,
            $deployment,
            (int) ($json['usage']['prompt_tokens'] ?? 0),
            (int) ($json['usage']['completion_tokens'] ?? 0),
            (int) round((hrtime(true) - $started) / 1_000_000),
        );
    }

    public function embed(ProviderSettings $settings, string $text): array
    {
        $deployment = (string) ($settings->extra['embedding_deployment'] ?? $settings->embeddingModel);
        $json = $this->post($settings, "deployments/{$deployment}/embeddings", ['input' => $text]);
        $vector = $json['data'][0]['embedding'] ?? null;

        if (! is_array($vector)) {
            throw AiProviderUnavailableException::failed($this->provider()->value, 'unreadable embedding');
        }

        return array_values(array_map(floatval(...), $vector));
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function post(ProviderSettings $settings, string $path, array $body): array
    {
        $version = (string) ($settings->extra['api_version'] ?? self::DefaultApiVersion);

        try {
            $response = Http::timeout(60)->acceptJson()->withHeaders(['api-key' => (string) $settings->apiKey])
                ->post(rtrim((string) $settings->endpoint, '/').'/openai/'.$path.'?api-version='.urlencode($version), $body);
        } catch (Throwable $exception) {
            throw AiProviderUnavailableException::failed($this->provider()->value, $exception::class);
        }

        if (! $response->successful()) {
            throw AiProviderUnavailableException::failed($this->provider()->value, 'HTTP '.$response->status());
        }

        /** @var array<string, mixed> $json */
        $json = (array) $response->json();

        return $json;
    }
}
