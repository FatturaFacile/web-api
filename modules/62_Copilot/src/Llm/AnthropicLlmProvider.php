<?php

declare(strict_types=1);

namespace Modules\Copilot\Llm;

use Illuminate\Support\Facades\Http;
use Modules\Copilot\Contracts\LlmProviderInterface;
use Modules\Copilot\DTOs\LlmRequest;
use Modules\Copilot\DTOs\LlmResponse;
use Modules\Copilot\DTOs\LlmToolCall;
use Modules\Copilot\DTOs\TokenUsage;

/**
 * Anthropic Messages API with tool use. System messages become the `system` field; the results of the tool calls of one
 * turn are grouped in a single user message, as the API requires.
 */
final readonly class AnthropicLlmProvider implements LlmProviderInterface
{
    public function __construct(
        private ?string $apiKey,
        private string $baseUrl,
        private ?string $defaultModel,
    ) {}

    public function name(): string
    {
        return 'anthropic';
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
        $messages = [];

        foreach ($request->messages as $message) {
            if ($message->role === 'system') {
                $system[] = $message->content;

                continue;
            }

            if ($message->role === 'tool') {
                $block = ['type' => 'tool_result', 'tool_use_id' => $message->toolCallId, 'content' => $message->content];
                $last = array_key_last($messages);

                if ($last !== null && $messages[$last]['role'] === 'user' && is_array($messages[$last]['content']) && ($messages[$last]['content'][0]['type'] ?? null) === 'tool_result') {
                    $messages[$last]['content'][] = $block;
                } else {
                    $messages[] = ['role' => 'user', 'content' => [$block]];
                }

                continue;
            }

            if ($message->role === 'assistant' && $message->toolCalls !== []) {
                $blocks = $message->content !== '' ? [['type' => 'text', 'text' => $message->content]] : [];
                foreach ($message->toolCalls as $call) {
                    $blocks[] = ['type' => 'tool_use', 'id' => $call->id, 'name' => $call->name, 'input' => (object) $call->arguments];
                }
                $messages[] = ['role' => 'assistant', 'content' => $blocks];

                continue;
            }

            $messages[] = ['role' => $message->role, 'content' => $message->content];
        }

        $body = [
            'model' => $model,
            'max_tokens' => $request->maxTokens,
            'temperature' => $request->temperature,
            'system' => implode("\n\n", $system),
            'messages' => $messages,
        ];

        if ($request->tools !== []) {
            $body['tools'] = array_map(static fn (array $tool): array => [
                'name' => $tool['name'],
                'description' => $tool['description'],
                'input_schema' => $tool['parameters'],
            ], $request->tools);
        }

        $response = Http::timeout(60)
            ->withHeaders(['x-api-key' => $this->apiKey, 'anthropic-version' => '2023-06-01'])
            ->acceptJson()
            ->post(rtrim($this->baseUrl, '/').'/v1/messages', $body);

        if (! $response->successful()) {
            throw CopilotProviderException::failed($this->name(), $response->status());
        }

        $blocks = $response->json('content');

        if (! is_array($blocks)) {
            throw CopilotProviderException::unreadable($this->name());
        }

        $text = '';
        $toolCalls = [];

        foreach ($blocks as $block) {
            if (($block['type'] ?? null) === 'text') {
                $text .= (string) $block['text'];
            } elseif (($block['type'] ?? null) === 'tool_use') {
                $toolCalls[] = new LlmToolCall((string) $block['id'], (string) $block['name'], is_array($block['input'] ?? null) ? $block['input'] : []);
            }
        }

        return new LlmResponse($text, $toolCalls, new TokenUsage((int) $response->json('usage.input_tokens', 0), (int) $response->json('usage.output_tokens', 0), $this->name(), (string) $model));
    }
}
