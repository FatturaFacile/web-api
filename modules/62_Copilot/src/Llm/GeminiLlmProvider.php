<?php

declare(strict_types=1);

namespace Modules\Copilot\Llm;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Copilot\Contracts\LlmProviderInterface;
use Modules\Copilot\DTOs\LlmRequest;
use Modules\Copilot\DTOs\LlmResponse;
use Modules\Copilot\DTOs\LlmToolCall;
use Modules\Copilot\DTOs\TokenUsage;

/**
 * Google Gemini generateContent with function calling. Gemini returns no call ids, so one is generated and the result
 * is matched back by the function name.
 */
final readonly class GeminiLlmProvider implements LlmProviderInterface
{
    public function __construct(
        private ?string $apiKey,
        private string $baseUrl,
        private ?string $defaultModel,
    ) {}

    public function name(): string
    {
        return 'gemini';
    }

    public function isExternal(): bool
    {
        return true;
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        $model = $request->model ?? $this->defaultModel;

        if (empty($this->apiKey) || empty($model)) {
            throw CopilotProviderException::notConfigured($this->name());
        }

        $system = [];
        $contents = [];

        foreach ($request->messages as $message) {
            if ($message->role === 'system') {
                $system[] = $message->content;
            } elseif ($message->role === 'tool') {
                $decoded = json_decode($message->content, true);
                $contents[] = ['role' => 'user', 'parts' => [['functionResponse' => ['name' => (string) $message->toolName, 'response' => ['result' => $decoded ?? $message->content]]]]];
            } elseif ($message->role === 'assistant') {
                $parts = $message->content !== '' ? [['text' => $message->content]] : [];
                foreach ($message->toolCalls as $call) {
                    $parts[] = ['functionCall' => ['name' => $call->name, 'args' => (object) $call->arguments]];
                }
                $contents[] = ['role' => 'model', 'parts' => $parts];
            } else {
                $contents[] = ['role' => 'user', 'parts' => [['text' => $message->content]]];
            }
        }

        $body = [
            'contents' => $contents,
            'generationConfig' => ['temperature' => $request->temperature, 'maxOutputTokens' => $request->maxTokens],
        ];

        if ($system !== []) {
            $body['systemInstruction'] = ['parts' => [['text' => implode("\n\n", $system)]]];
        }

        if ($request->tools !== []) {
            $body['tools'] = [['functionDeclarations' => array_map(fn (array $tool): array => [
                'name' => $tool['name'],
                'description' => $tool['description'],
                'parameters' => $this->schema($tool['parameters']),
            ], $request->tools)]];
        }

        $response = Http::timeout(60)
            ->withHeaders(['x-goog-api-key' => $this->apiKey])
            ->acceptJson()
            ->post(rtrim($this->baseUrl, '/')."/models/{$model}:generateContent", $body);

        if (! $response->successful()) {
            throw CopilotProviderException::failed($this->name(), $response->status());
        }

        $parts = $response->json('candidates.0.content.parts');

        if (! is_array($parts)) {
            throw CopilotProviderException::unreadable($this->name());
        }

        $text = '';
        $toolCalls = [];

        foreach ($parts as $part) {
            if (isset($part['text'])) {
                $text .= (string) $part['text'];
            } elseif (isset($part['functionCall'])) {
                $toolCalls[] = new LlmToolCall('call_'.Str::random(12), (string) $part['functionCall']['name'], is_array($part['functionCall']['args'] ?? null) ? $part['functionCall']['args'] : []);
            }
        }

        return new LlmResponse($text, $toolCalls, new TokenUsage((int) $response->json('usageMetadata.promptTokenCount', 0), (int) $response->json('usageMetadata.candidatesTokenCount', 0), $this->name(), (string) $model));
    }

    /**
     * Gemini accepts a subset of JSON Schema: `additionalProperties` is refused.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function schema(array $schema): array
    {
        unset($schema['additionalProperties']);

        foreach ($schema as $key => $value) {
            if (is_array($value)) {
                $schema[$key] = $this->schema($value);
            }
        }

        return $schema;
    }
}
